A place to store examples of my work.  Starting with my HLS segmenter.

HLS Segmenter: streamer.php

HTTP Live streaming was a new technology when I first wrote this script, and there weren't many ready-made tools available for it at the time.  There were about 200 videos that needed to be processed in the initial load, and I needed a way to automate the HLS segments at each resolution addition to the M3U8 playlists. An MP4 suitable for progressive download was also required for as a fallback for devices which did not support HLS.  The files created were then uploaded to Amazon S3, and distributed through Amazon CloudFront.
