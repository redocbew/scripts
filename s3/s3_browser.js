/**
 *  s3_browser.js
 *  A file browser for files uploaded through s3_client.
 **/

$(function() {
  $('#s3_browser').find('span.s3_folder').click(
    // open / close folders
    function () {
      toggle_browser_folder($(this));
    }
  );

  $('#s3_browser').find('div.s3_delete > img').click(
    // delete object from S3
    function () {
      delete_browser_object($(this));
    }
  );

  $('#s3_share_filepost').click(
    function() {
      var ajax_params = encodeURIComponent($('#s3_share_emails').val());
      ajax_params = ajax_params + '/' + encodeURIComponent($('#s3_share_nid').val());
      ajax_params = ajax_params + '/' + Drupal.settings.s3_uploader.token;

      $.ajax({
        type: 'GET',
        url: '/s3/js/share/' + ajax_params,
        cache: false,
        dataType: 'text',
        success: function(mail_sent) {
          if($.parseJSON(mail_sent)) {
            display_share_message('Files shared.');
          } else {
            display_share_message('Failed to send email.');
          }
        }
      });
    }
  );
});