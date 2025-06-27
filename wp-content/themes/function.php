<?php
// In your theme’s functions.php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 1) Register REST routes for each pipeline step.
 */
add_action('rest_api_init', function() {
    register_rest_route('oml/v1','/generate-script', [
        'methods'             => 'POST',
        'callback'            => 'oml_generate_script',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('oml/v1','/fact-check', [
        'methods'             => 'POST',
        'callback'            => 'oml_fact_check_script',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('oml/v1','/generate-voice', [
        'methods'             => 'POST',
        'callback'            => 'oml_generate_voiceovers',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('oml/v1','/merge-audio', [
        'methods'             => 'POST',
        'callback'            => 'oml_merge_audio',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * 2) 5-Chapter Script Generator via OpenAI
 */
function oml_generate_script( WP_REST_Request $req ) {
    $family = sanitize_text_field( $req->get_param('family_name') );
    $facts  = $req->get_param('facts'); // an array of key facts

    $prompt = "Write a luxury storytelling 5-chapter article about the {$family} family, 
    chapter length ~490 words, chapter1 Piers Morgan style witty drama, chapters2–5 in Caro style.\n\nFacts:\n" 
    . implode("\n", $facts);

    $body = [
        "model"    => "gpt-4.5-turbo",
        "messages" => [ [ "role"=>"system","content"=>"You are a luxury historian." ],
                        [ "role"=>"user","content"=>$prompt ] ],
        "max_tokens" => 3000
    ];

    $response = oml_call_openai( $body );
    if ( ! isset($response['choices'][0]['message']['content']) ) {
        return new WP_Error('openai_failed','OpenAI did not return text', ['status'=>500]);
    }

    $script = $response['choices'][0]['message']['content'];
    // Store transient for later steps
    set_transient("oml_script_{$family}", $script, 12*HOUR_IN_SECONDS);

    return rest_ensure_response([
        'success' => true,
        'script'  => $script,
    ]);
}

/**
 * 3) Fact-check the generated script via Perplexity
 */
function oml_fact_check_script( WP_REST_Request $req ) {
    $family = sanitize_text_field( $req->get_param('family_name') );
    $script = get_transient("oml_script_{$family}");
    if ( ! $script ) {
        return new WP_Error('no_script','No script found – run /generate-script first', ['status'=>400]);
    }

    // call Perplexity
    $key = getenv('PERPLEXITY_API_KEY');
    $url = "https://api.perplexity.ai/factcheck";
    $data = oml_call_api($url, 'POST', ["Authorization"=>"Bearer {$key}","Content-Type"=>"application/json"], [
        'text'    => $script,
        'verbose' => true,
    ]);

    if ( empty($data['checkedText']) ) {
        return new WP_Error('factcheck_failed','Perplexity failed to return checked text', ['status'=>500]);
    }

    $checked = $data['checkedText'];
    set_transient("oml_checked_{$family}", $checked, 12*HOUR_IN_SECONDS);

    return rest_ensure_response([
        'success'      => true,
        'checked_text' => $checked,
        'issues'       => $data['issues'],  // array of flagged issues
    ]);
}

/**
 * 4) Generate individual voiceovers via ElevenLabs
 */
function oml_generate_voiceovers( WP_REST_Request $req ) {
    $family   = sanitize_text_field( $req->get_param('family_name') );
    $script   = get_transient("oml_checked_{$family}");
    if ( ! $script ) {
        return new WP_Error('no_checked','No checked script – run /fact-check first', ['status'=>400]);
    }

    // Split chapters by delimiter (assuming “Chapter 1:” etc.)
    preg_match_all('/Chapter\s+\d+:(.*?)(?=Chapter\s+\d+:|$)/is', $script, $m);
    $chapters = $m[0];

    $api_key  = getenv('ELEVENLABS_API_KEY');
    $voiceA   = 'voice-model-a'; // typo keys from ElevenLabs console
    $voiceB   = 'voice-model-b';

    $upload_dir = wp_upload_dir();
    $folder     = trailingslashit($upload_dir['basedir']) . "oml-{$family}";
    wp_mkdir_p( $folder );

    $files = [];
    foreach( $chapters as $i => $text ) {
        $model = ($i===0) ? $voiceA : $voiceB;
        $res   = oml_call_api(
            "https://api.elevenlabs.io/v1/tts/{$model}/stream",
            'POST', 
            [
                "xi-api-key" => $api_key,
                "Content-Type" => "application/json"
            ],
            ["text"=>$text]
        );
        // $res is raw MP3 binary
        $filename = "{$folder}/ch" . ($i+1) . ".mp3";
        file_put_contents( $filename, $res );
        $files[] = $filename;
    }

    // store list of files
    set_transient("oml_voicefiles_{$family}", $files, 12*HOUR_IN_SECONDS);

    return rest_ensure_response([
        'success' => true,
        'files'   => $files,
    ]);
}

/**
 * 5) Merge the MP3s into one final file via ffmpeg
 */
function oml_merge_audio( WP_REST_Request $req ) {
    $family = sanitize_text_field( $req->get_param('family_name') );
    $files  = get_transient("oml_voicefiles_{$family}");
    if ( empty($files) ) {
        return new WP_Error('no_audio','No chapter audio – run /generate-voice first', ['status'=>400]);
    }

    $upload_dir = wp_upload_dir();
    $out_dir    = trailingslashit($upload_dir['basedir']) . "oml-{$family}";
    $out_file   = "{$out_dir}/{$family}_OML_Final_Voiceover.mp3";

    // create a ffmpeg concat file
    $concat = "{$out_dir}/concat.txt";
    $lines  = array_map(function($f){ return "file '" . str_replace("'", "'\\''", $f) . "'"; }, $files);
    file_put_contents( $concat, implode("\n", $lines) );

    // system call ffmpeg
    $cmd = "ffmpeg -y -f concat -safe 0 -i " . escapeshellarg($concat) 
         . " -c copy " . escapeshellarg($out_file);
    shell_exec($cmd);

    if ( ! file_exists($out_file) ) {
        return new WP_Error('merge_failed','ffmpeg failed to produce output', ['status'=>500]);
    }

    // Register with Media Library
    $attachment_id = oml_register_media($out_file, "{$family}_OML_Final_Voiceover.mp3", 'audio/mpeg');

    return rest_ensure_response([
        'success'       => true,
        'final_file'    => $out_file,
        'attachment_id' => $attachment_id,
    ]);
}

/**
 * Helper: Call OpenAI
 */
function oml_call_openai( $body ) {
    $key = getenv('OPENAI_API_KEY');
    return oml_call_api(
        'https://api.openai.com/v1/chat/completions',
        'POST',
        [
            "Authorization"=>"Bearer {$key}",
            "Content-Type"=>"application/json"
        ],
        $body
    );
}

/**
 * Generic cURL‐style request via wp_remote_request()
 */
function oml_call_api( $url, $method='GET', $headers=[], $body=[] ) {
    $args = ['method'=>$method,'headers'=>$headers];
    if ( ! empty($body) ) $args['body'] = json_encode($body);

    $res = wp_remote_request($url, $args);
    if ( is_wp_error($res) || wp_remote_retrieve_response_code($res)!==200 ) {
        error_log("OML API error: " . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res)));
        return [];
    }
    $data = wp_remote_retrieve_body($res);
    // ElevenLabs returns raw MP3 binary; detect JSON vs binary:
    $json = json_decode($data, true);
    return $json ?: $data;
}

/**
 * Helper: register audio file in WP Media Library
 */
function oml_register_media( $file_path, $filename, $mime='audio/mpeg' ) {
    $wp_filetype = wp_check_filetype($filename, null );
    $attachment = [
        'guid'           => wp_upload_dir()['url'] . "/{$filename}",
        'post_mime_type' => $mime,
        'post_title'     => sanitize_file_name($filename),
        'post_content'   => '',
        'post_status'    => 'inherit'
    ];
    $attach_id = wp_insert_attachment( $attachment, $file_path );
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
    wp_update_attachment_metadata( $attach_id, $attach_data );
    return $attach_id;
}
