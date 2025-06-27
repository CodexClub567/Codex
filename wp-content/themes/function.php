<?php
/**
 * OML Studio – functions.php
 * Production-ready pipeline for generating luxury scripts + voiceovers.
 */

// Bail if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init',           'oml_register_cpt'         );
add_action( 'rest_api_init',  'oml_register_rest_routes' );

// 1) Register Custom Post Type to track productions
function oml_register_cpt() {
    register_post_type( 'oml_production', [
        'labels' => [
            'name'          => 'OML Productions',
            'singular_name' => 'OML Production',
        ],
        'public'      => false,
        'show_ui'     => true,
        'supports'    => [ 'title' ],
        'capability_type' => 'post',
        'map_meta_cap'    => true,
    ]);
}

// 2) Expose REST endpoints to kick off a new production & fetch status
function oml_register_rest_routes() {
    register_rest_route( 'oml/v1', '/production', [
        'methods'             => 'POST',
        'callback'            => 'oml_rest_create_production',
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        }
    ] );
    register_rest_route( 'oml/v1', '/production/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'oml_rest_get_production',
        'permission_callback' => '__return_true',
    ] );
}

/**
 * 2a) Create a new production job
 */
function oml_rest_create_production( WP_REST_Request $req ) {
    $family = sanitize_text_field( $req->get_param( 'family_name' ) );
    $facts  = array_map( 'sanitize_text_field', (array) $req->get_param( 'facts' ) );

    if ( empty( $family ) || empty( $facts ) ) {
        return new WP_Error( 'oml_invalid_input', 'Family name and facts are required.', [ 'status' => 400 ] );
    }

    // Create CPT entry
    $post_id = wp_insert_post( [
        'post_type'   => 'oml_production',
        'post_title'  => $family,
        'post_status' => 'publish',
    ] );
    if ( is_wp_error( $post_id ) ) {
        return new WP_Error( 'oml_post_failed', 'Could not create production.', [ 'status' => 500 ] );
    }

    update_post_meta( $post_id, 'oml_family', $family );
    update_post_meta( $post_id, 'oml_facts', $facts );
    update_post_meta( $post_id, 'oml_status', 'queued_script' );

    // Schedule background job for script generation
    as_schedule_single_action( time(), 'oml_do_generate_script', [ 'post_id' => $post_id ] );

    return rest_ensure_response( [
        'id'     => $post_id,
        'status' => 'queued_script',
        'message'=> 'Script generation queued.',
    ] );
}

/**
 * 2b) Fetch status & links for a production
 */
function oml_rest_get_production( WP_REST_Request $req ) {
    $id = absint( $req->get_param( 'id' ) );
    $post = get_post( $id );
    if ( ! $post || $post->post_type !== 'oml_production' ) {
        return new WP_Error( 'oml_not_found', 'Production not found.', [ 'status' => 404 ] );
    }

    $meta = [
        'family'       => get_post_meta( $id, 'oml_family', true ),
        'status'       => get_post_meta( $id, 'oml_status', true ),
        'script'       => get_post_meta( $id, 'oml_script', true ),
        'checked'      => get_post_meta( $id, 'oml_checked_script', true ),
        'voice_files'  => get_post_meta( $id, 'oml_voice_files', true ),
        'final_audio'  => get_post_meta( $id, 'oml_final_audio', true ),
        'attachment'   => get_post_meta( $id, 'oml_final_attachment', true ),
    ];
    return rest_ensure_response( [ 'id' => $id, 'data' => $meta ] );
}

/*
|--------------------------------------------------------------------------
| Action Scheduler hooks
|--------------------------------------------------------------------------
|
| 3) Generate script -> 4) Fact­check -> 5) Voice -> 6) Merge audio
|
*/

// 3) Generate 5-chapter script via OpenAI
add_action( 'oml_do_generate_script', function( $args ) {
    $post_id = intval( $args['post_id'] );
    $family  = get_post_meta( $post_id, 'oml_family', true );
    $facts   = get_post_meta( $post_id, 'oml_facts', true );

    $prompt = sprintf(
        "Write a formal, 5-chapter luxury storytelling piece (~490-495 words each). "
      . "Chapter 1 in witty, dramatic Piers Morgan style; Chapters 2-5 in deep, narrative Robert A. Caro style. "
      . "Facts:\n%s",
      implode( "\n", $facts )
    );

    $body = [
        'model'    => 'gpt-4.5-turbo',
        'messages' => [
            [ 'role'=>'system', 'content'=>'You are a luxury historian etching opulent narratives.' ],
            [ 'role'=>'user',   'content'=>$prompt ],
        ],
        'max_tokens' => 3000,
    ];
    $openai = oml_call_openai( $body );
    $script = $openai['choices'][0]['message']['content'] ?? '';

    if ( empty( $script ) ) {
        update_post_meta( $post_id, 'oml_status', 'error_script' );
        return;
    }

    update_post_meta( $post_id, 'oml_script', $script );
    update_post_meta( $post_id, 'oml_status', 'queued_factcheck' );

    // Next step
    as_schedule_single_action( time() + 5, 'oml_do_fact_check', [ 'post_id' => $post_id ] );
});

// 4) Fact-check via Perplexity
add_action( 'oml_do_fact_check', function( $args ) {
    $post_id = intval( $args['post_id'] );
    $script  = get_post_meta( $post_id, 'oml_script', true );
    if ( ! $script ) {
        update_post_meta( $post_id, 'oml_status', 'error_no_script' );
        return;
    }

    $perp_key = getenv( 'PERPLEXITY_API_KEY' );
    $resp = oml_call_api(
        'https://api.perplexity.ai/factcheck',
        'POST',
        [ 'Authorization'=>"Bearer {$perp_key}", 'Content-Type'=>'application/json' ],
        [ 'text'=>$script, 'verbose'=>false ]
    );
    $checked = $resp['checkedText'] ?? '';

    if ( empty( $checked ) ) {
        update_post_meta( $post_id, 'oml_status', 'error_factcheck' );
        return;
    }

    update_post_meta( $post_id, 'oml_checked_script', $checked );
    update_post_meta( $post_id, 'oml_status', 'queued_voice' );

    as_schedule_single_action( time()+5, 'oml_do_generate_voice', [ 'post_id'=>$post_id ] );
});

// 5) ElevenLabs TTS for each chapter
add_action( 'oml_do_generate_voice', function( $args ) {
    $post_id = intval( $args['post_id'] );
    $checked = get_post_meta( $post_id, 'oml_checked_script', true );
    if ( ! $checked ) {
        update_post_meta( $post_id, 'oml_status', 'error_no_checked' );
        return;
    }

    // split on “Chapter 1: … Chapter 2: …”
    preg_match_all( '/(Chapter\s+\d+:.*?)(?=Chapter\s+\d+:|$)/is', $checked, $m );
    $chapters = $m[1] ?? [];
    if ( count( $chapters ) !== 5 ) {
        update_post_meta( $post_id, 'oml_status', 'error_split' );
        return;
    }

    $upload_dir = wp_upload_dir();
    $dir        = $upload_dir['basedir'] . "/oml-{$post_id}";
    wp_mkdir_p( $dir );

    $eleven_key = getenv( 'ELEVENLABS_API_KEY' );
    $voiceA = '21m00Tcm4TlvDq8ikWAM'; // example ElevenLabs voice ID for witty style
    $voiceB = 'AZnzlk1XvdvUeBnXmlld'; // deep, reflective voice ID

    $mp3s = [];
    foreach ( $chapters as $i => $text ) {
        $voice = $i === 0 ? $voiceA : $voiceB;
        $resp = oml_call_api(
            "https://api.elevenlabs.io/v1/text-to-speech/{$voice}/stream",
            'POST',
            [ 'xi-api-key'=>$eleven_key, 'Content-Type'=>'application/json' ],
            [ 'text'=>$text ]
        );
        $path = "{$dir}/chapter-" . ($i+1) . ".mp3";
        file_put_contents( $path, $resp );
        $mp3s[] = $path;
    }

    update_post_meta( $post_id, 'oml_voice_files', $mp3s );
    update_post_meta( $post_id, 'oml_status', 'queued_merge' );

    as_schedule_single_action( time()+5, 'oml_do_merge_audio', [ 'post_id'=>$post_id ] );
});

// 6) Merge via ffmpeg + register in Media Library
add_action( 'oml_do_merge_audio', function( $args ) {
    $post_id = intval( $args['post_id'] );
    $files   = get_post_meta( $post_id, 'oml_voice_files', true );
    if ( empty( $files ) ) {
        update_post_meta( $post_id, 'oml_status', 'error_no_voices' );
        return;
    }

    $upload = wp_upload_dir();
    $dir    = $upload['basedir'] . "/oml-{$post_id}";
    $out    = "{$dir}/{$post_id}_OML_Final_Voiceover.mp3";

    // build concat list
    $txt = "{$dir}/concat.txt";
    $lines = array_map( fn($f)=> "file '{$f}'", $files );
    file_put_contents( $txt, implode( "\n", $lines ) );

    $cmd = "ffmpeg -y -f concat -safe 0 -i " . escapeshellarg( $txt ) 
         . " -c copy " . escapeshellarg( $out );
    shell_exec( $cmd );

    if ( ! file_exists( $out ) ) {
        update_post_meta( $post_id, 'oml_status', 'error_merge' );
        return;
    }

    // register in media library
    $attach_id = oml_register_media( $out );
    update_post_meta( $post_id, 'oml_final_audio', $out );
    update_post_meta( $post_id, 'oml_final_attachment', $attach_id );
    update_post_meta( $post_id, 'oml_status', 'complete' );
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function oml_call_openai( array $body ) {
    return oml_call_api(
        'https://api.openai.com/v1/chat/completions',
        'POST',
        [
            'Authorization' => 'Bearer ' . getenv( 'OPENAI_API_KEY' ),
            'Content-Type'  => 'application/json',
        ],
        $body
    );
}

function oml_call_api( string $url, string $method='GET', array $headers=[], $body=null ) {
    $args = [ 'method'=>$method, 'headers'=>$headers ];
    if ( $body !== null ) {
        $args['body'] = wp_json_encode( $body );
    }
    $res = wp_remote_request( $url, $args );
    if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
        error_log( "OML API error: " . ( is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res ) ) );
        return [];
    }
    $raw = wp_remote_retrieve_body( $res );
    return json_decode( $raw, true ) ?: $raw;
}

function oml_register_media( $file_path ) {
    $filetype = wp_check_filetype( $file_path );
    $attachment = [
        'guid'           => wp_upload_dir()['url'] . '/' . basename( $file_path ),
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name( basename( $file_path ) ),
        'post_status'    => 'inherit',
    ];
    $attach_id = wp_insert_attachment( $attachment, $file_path );
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $meta = wp_generate_attachment_metadata( $attach_id, $file_path );
    wp_update_attachment_metadata( $attach_id, $meta );
    return $attach_id;
}
