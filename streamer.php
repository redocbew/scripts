<?php

/**
* A wrapper around ffmpeg for creating an HLS compliant stream.  The script creates 
* all required .ts fragments, the variant playlists, the master playlist, and an MP4
* as a fallback for devices which do not support HLS.
*/

// Bits per pixel of an H.264 video... some people hate K-values as being inaccurate
// and misleading, but for the purposes of calculating estimated bandwidth this seems
// close enough.
define('H264_K', 0.012345);

// Quality scalar, 0 to 1.0... .45 "quality" still seems pretty good.
define('H264_QUALITY', 0.45);

// disk paths for HLS segments
define('HLS_INPUT_DIR' , 'C:\\hls_segmenter\\queue');
define('HLS_OUTPUT_DIR', 'C:\\hls_segmenter\\encoded');
define('MP4_OUTPUT_DIR', 'C:\\hls_segmenter\\fallback');

// MP4 frame dimensions
define('MP4_PRESET_WIDTH' , '640');
define('MP4_PRESET_HEIGHT', '360');

// The resolutions to be encoded, keyed by the frame height of each HLS preset.
// Each of these keys should match to a directory created inside HLS_INPUT_DIR
// where the input videos will be found.
$presets = array(
  1080 => array(360 => 640, 480 => 854, 540 => 960, 720 => 1280, 1080 => 1920),
  720  => array(360 => 640, 480 => 854, 540 => 960, 720 => 1280),    
  540  => array(360 => 640, 480 => 854, 540 => 960),
  480  => array(360 => 640, 480 => 854),
  360  => array(360 => 640),
);

foreach($presets as $source_preset => $preset_list) {
  $source_dir = HLS_INPUT_DIR."\\{$source_preset}";  
  if(!is_dir($source_dir)) {
    print HLS_INPUT_DIR."\\{$source_preset} is not a valid directory. Skipping to next preset.\n";
    continue;    
  }

  $filenames  = explode("\n", trim(shell_exec("dir /b {$source_dir}")));
  if(count($filenames) == 1 && empty($filenames[0])) {
    print "No files found in ".HLS_INPUT_DIR."\\{$source_preset} Skipping to next preset.\n";
    continue;
  }
  
  foreach($filenames as $filename) {
    $matches  = array();
    $playlist = "#EXTM3U\n";
    $output   = str_replace("\n", '', shell_exec("ffprobe -v quiet -show_streams {$source_dir}\\{$filename}"));
    $filename = str_replace('.mp4', '', $filename);
    
    preg_match('/width=(\d+)height=(\d+)/', $output, $matches);
    if(empty($matches[1]) || empty($matches[2])) {
      print 'Failed to extract video stats from ffprobe output for file '.$filename."\n";
      continue;
    }

    $source_width  = $matches[1];
    $source_height = $matches[2];
    foreach($preset_list as $preset_height => $preset_width) {
      $playlist .= encode_hls_segments($source_dir, $filename, $source_width, $source_height, $preset_width, $preset_height);
      // do some post-processing on the playlist to remove hardcoded disk paths
      $variant = file_get_contents(HLS_OUTPUT_DIR.'\\'.$preset_height.'\\'.$filename.'_'.$preset_height.'.m3u8');
      $variant = str_replace(HLS_OUTPUT_DIR.'\\'.$preset_height.'\\', '', $variant);
      file_put_contents(HLS_OUTPUT_DIR.'\\'.$preset_height.'\\'.$filename.'_'.$preset_height.'.m3u8', $variant);
      print "WOOHOO! {$filename}.mp4 has been encoded for HLS at {$preset_height}p\n";      
    }

    file_put_contents(HLS_OUTPUT_DIR.'\\'.$filename.'.m3u8', $playlist);
    encode_hls_fallback($source_dir, $filename, $source_width, $source_height);
    print "WOOHOO! {$filename}.mp4 has been encoded as an HLS fallback at ".MP4_PRESET_HEIGHT."p\n";
  }
}


/**
 * Encode and perform segmentation in order to create the files necessary for HLS playback
 * @param string  $source_dir    The directory containing the input video
 * @param string  $filename      The filename of the input video
 * @param integer $source_width  Frame width of the input video
 * @param integer $source_height Frame height of the input video
 * @param integer $preset_width  Frame width of the HLS preset
 * @param integer $preset_height Frame height of the HLS preset
 */
function encode_hls_segments($source_dir, $filename, $source_width, $source_height, $preset_width, $preset_height) {
  $args = array();

  $args['y']            = '';                                // overwrite previous output files without asking
  $args['v']            = 'warning';                         // show only the things which might cause me trouble
  $args['i']            = $source_dir.'\\'.$filename.'.mp4'; // input filename
  $args['c:v']          = 'libx264';                         // encode with H.264 video
  $args['preset']       = 'slow';                            // H.264 encoding preset... slow is good here
  $args['crf']          = '18';                              // quality scale for an H.264 output.  Allows for variable bitrate, quality based encoding.
  $args['pix_fmt']      = 'yuv420p';                         // color space, or pixel format in use
  $args['f']            = 'segment';                         // output file type.
  $args['segment_time'] = '10';                              // segment length in seconds.  Recommended length from Apple is 10 seconds for HLS streaming.

  if($preset_height <= 360) {
    $args['profile:v'] = 'baseline';  // use the baseline profile for low res videos sent to devices which might be old, slow, or strange
  } else {
    $args['profile:v'] = 'main';      // use the main profile for everything else
  }
  
  $args['c:a']            = 'libvo_aacenc';                                                         // encode with AAC audio	  	  
  $args['segment_list']   = HLS_OUTPUT_DIR."\\{$preset_height}\\{$filename}_{$preset_height}.m3u8"; // output path for variant playlists
  $args['segment_format'] = 'mpeg_ts';                                                              // set segment format
  $args['map']            = '0';                                                                    // map all outputs to the same input file
  $args['flags']	  = '-global_header';                                                       // MPEG-2 transport streams require some extra information in each frame
  $args['g']              = '100';                                                                  // set maximum GOP size
  $args['keyint_min']     = '100';                                                                  // set minimum GOP size
  $args['sc_threshold']   = '0';                                                                    // disable insertion of i-frames at scene change
  
  $width  = $source_width;
  $height = $source_height;
  if($source_width > $preset_width || $source_height > $preset_height) {
    list($width, $height) = get_dimensions($source_width, $source_height, $preset_width, $preset_height);
    $args['s'] = $width.'x'.$height;
  }

  $arg_string = '';
  foreach($args as $key => $value) {
    $arg_string .= ' -'.$key.' '.$value;
  }

  // perform segmentation
  shell_exec("ffmpeg{$arg_string} ".HLS_OUTPUT_DIR."\\{$preset_height}\\{$filename}_{$preset_height}_%03d.ts");
  $bandwidth = intval(H264_K * H264_QUALITY * $height * $width * 1024);
  
  $playlist = '#EXT-X-STREAM-INF:PROGRAM-ID=1,RESOLUTION='.$width.'x'.$height.',NAME="'.$preset_height.'p",BANDWIDTH='.$bandwidth."\n";
  $playlist .= $preset_height.'/'.$filename.'_'.$preset_height.".m3u8\n";
  return $playlist;
}


/**
 * Encode an mp4 video to act as a fallback for devices which do not support HLS
 * @param string  $source_dir    The directory containing the input video
 * @param string  $filename      The filename of the input video
 * @param integer $source_width  Frame width of the input video
 * @param integer $source_height Frame height of the input video
 */
function encode_hls_fallback($source_dir, $filename, $source_width, $source_height) {
  $args = array();
  $args['y']         = '';                                // overwrite previous output files without asking
  $args['v']         = 'warning';                         // show only the things which might cause me trouble
  $args['i']         = $source_dir.'\\'.$filename.'.mp4'; // input filename
  $args['c:v']       = 'libx264';                         // encode with H.264 video
  $args['preset']    = 'slow';                            // H.264 encoding preset... slow is good here
  $args['profile:v'] = 'baseline';                        // H.264 encoding profile

  if($source_width > MP4_PRESET_WIDTH || $source_height > MP4_PRESET_HEIGHT) {
    list($width, $height) = get_dimensions($source_width, $source_height, MP4_PRESET_WIDTH, MP4_PRESET_HEIGHT);
    $args['s'] = $width.'x'.$height;
  }
  
  $args['c:a']     = 'libvo_aacenc';  // encode with AAC audio	  	  
  $args['crf']     = '21';            // constant rate factor for an H.264 output.  Allows for variable bitrate, quality based encoding.
  $args['pix_fmt'] = 'yuv420p';       // color space, or pixel format in use
  
  $arg_string = '';
  foreach($args as $key => $value) {
    $arg_string .= ' -'.$key.' '.$value;
  }

  // perform encode
  shell_exec("ffmpeg{$arg_string} ".MP4_OUTPUT_DIR."\\{$filename}.mp4");
}


/**
 * Helper function for determining the correct resolution, while maintaining aspect ratio.
 * ffmpeg has an option which will do this for me, but I didn't find it until after this was
 * written
 * @param integer $source_width  Frame width of the input video
 * @param integer $source_height Frame height of the input video
 * @param integer $preset_width  Frame width of the HLS preset
 * @param integer $preset_height Frame height of the HLS preset
 */
function get_dimensions($source_width, $source_height, $preset_width, $preset_height) {
  $aspect_ratio = $source_width / $source_height;
  // set height to preset height and scale width preserving aspect ratio
  $width  = intval($preset_height * $aspect_ratio) % 2 == 0 ? intval($preset_height * $aspect_ratio) : intval($preset_height * $aspect_ratio)-1;
  $height = $preset_height;
  
  return array($width, $height);
}
