/**
 *  s3_uploader.js
 *  Integration of plupload and s3_client for browser-based, multi-file uploads
 *  direct to S3.
 **/

var params = {};
var uploader;

$(function() {
	uploader = new plupload.Uploader({
		runtimes      : 'html5,flash',
		browse_button : 's3_add_files',
		container     : 'plupload_wrapper',
		url           : 'https://s3.amazonaws.com/' + Drupal.settings.s3_uploader.bucket,
		flash_swf_url : '/' + Drupal.settings.s3_uploader.module_path + '/includes/plupload/js/Moxie.swf',
		multipart     : true,
		filters       : get_uploader_file_filters()
	});

  // add new folder to the uploader
	$('#s3_create_folder').click(function() {
    // the s3_active id tracks the most recently opened folder
    var folder_name   = $('#s3_folder_name').val();
    var folder_parent = $('#s3_active');
    var path_id       = '';

    // check for click-happy users
    if(folder_name == '') {
      return;
    }

    // contend with leading and trailing slashes
    if(folder_name.substr(0, 1) == '/') {
      folder_name = folder_name.substr(1, folder_name.length-1);
    }

    if(folder_name.substr(folder_name.length-1) == '/') {
      folder_name = folder_name.substr(0, folder_name.length-1);
    }

    folder_name = folder_name.replace(/[^a-zA-Z0-9 ]/g, '');
    // reset to operate on the active subfolder if one is open
    if(!$('#s3_active').hasClass('s3_uploads')) {
      path_id       = $('#s3_active > div.s3_link').attr('id');
      folder_parent = $('#' + $('#s3_active').find('div.s3_link > span').attr('src'));
    }

    var folder_list = folder_parent.children('div.s3_row').find('span');
    for(var i=0; i < folder_list.length; i++) {
      if($(folder_list[i]).text() == folder_name) {
        alert('There already exists a folder with this name.');
        return;
      }
    }

    // reset active folder and append new folder to its parent
    $('#s3_active').removeAttr('id');
    if(folder_parent.children('span').hasClass('s3_empty')) {
      folder_parent.children('span.s3_empty').remove();
    }

    folder_parent.append(get_uploader_row(path_id, folder_name, 'folder'));
    remove_uploader_message();

    // fade in new folder and bind click handlers to the newly created elements.
    // The 's3_bind' class is used to identify the elements which need binding
    // with .on()
    $('#s3_active').fadeIn('fast', function () {
      $(this).removeClass('s3_hidden');
      $(this).find('img.s3_bind').on('click', remove_uploader_folder);
      $(this).find('img.s3_bind').removeClass('s3_bind');
      $(this).find('div.s3_link > span.s3_bind').on('click', toggle_uploader_folder);
      $(this).find('div.s3_link > span.s3_bind').removeClass('s3_bind');
    });

    // clear 'new folder' text box after folder is created
    $('#s3_folder_name').val('');
	});

/*************** plupload integration ***************/

  // begin upload when button is clicked
	$('#s3_upload').click(function(e) {
    if($('.s3_uploads').find('span.s3_file').length > 0) {
      uploader.start();
      e.preventDefault();
    }
	});

	uploader.init();
  // plupload freaks out if you hide the browse button before calling init()
  if(Drupal.settings.s3_uploader.view == 'node_form') {
    display_uploader_message('Begin by selecting a title for this filepost in the "Title" box above.');
    $('#s3_add_files').attr('disabled', true);

    $('#edit-title').keyup(function() {
      var has_no_title = $(this).val() == '';
      uploader.disableBrowse(has_no_title);
      $('#s3_add_files').attr('disabled', has_no_title);

      if(has_no_title) {
        display_uploader_message('Begin by selecting a title for this filepost in the "Title" box above.');
      } else {
        remove_uploader_message();
      }
    });
  }

  // display files selected for upload, generate upload policies, and bind click
  // handlers for removal
	uploader.bind('FilesAdded', function(up, files) {
    var loader_id;
    var ajax_url = '/s3/js/upload_policy';

    display_uploader_message('Adding files...');
    // node title becomes the first "subfolder" within the bucket
    if(Drupal.settings.s3_uploader.view == 'browser_embed') {
      ajax_url = ajax_url + '/' + Drupal.settings.s3_uploader.prefix;
    } else {
      var title = encodeURIComponent($('#edit-title').val().replace(/[^a-zA-Z0-9 ]/g, ''));
      ajax_url  = ajax_url + '/' + title + Drupal.settings.s3_uploader.session_id;
    }

    // append any other subfolders to make sure the correct policy is generated
    // and the files are uploaded with the correct keys
    if(!$('#s3_active').hasClass('s3_uploads')) {
      var s3_link_id = $('#s3_active').find('div.s3_link').attr('id');
      var folder_ids = s3_link_id.substr(1, s3_link_id.length-1).split('/');

      for(var i=0; i < folder_ids.length; i++) {
        var folder = $('span.s3_folder[src="'+ folder_ids[i] +'"]');
        ajax_url = ajax_url + '/' + encodeURIComponent(folder.text());
      }
    }

    // check if these files were added to the root, or some subfolder
    if($('#s3_active').hasClass('s3_uploads')) {
      loader_id = 'div.s3_uploads';
    } else {
      loader_id = '#' + $('#s3_active').find('span.s3_open').attr('src');
    }

    // remove the 'no files selected/folder is empty' dummy row if present
    if($(loader_id + ' > span').hasClass('s3_empty')) {
      $(loader_id + ' > span').remove();
    }

    // disable edit title box once files have been added in case of overly
    // creative users
    if(Drupal.settings.s3_uploader.view == 'node_form') {
      if(files.length > 0) {
        $('#edit-title').attr('disabled', 'disabled');
      } else {
        $('#edit-title').removeAttr('disabled');
      }
    }

    // generate upload policy for each file and build multipart upload params
    var files_added = 0;
    $.each(files, function(i, file) {
      $.ajax({
        type: 'GET',
        url: ajax_url + '/' + encodeURIComponent(file.name),
        cache: false,
        dataType: 'text',
        success: function (json_data) {
          var data = $.parseJSON(json_data);
          if(data.success) {
            params[file.id] = {access_key_id: data.access_key_id, filename: data.filename, key: data.key, policy: data.policy, signature: data.signature};

            // append file row to loader
            $(loader_id).append(get_uploader_row('', file.name, 'file', plupload.formatSize(file.size), file.id));

            // bind click handler for removal
            $(loader_id + ' > div.s3_hidden').fadeIn('fast', function() {
              $(this).removeClass('s3_hidden');
              $('img.s3_bind').on('click', remove_uploader_file);
              $('img.s3_bind').removeClass('s3_bind');
            });
          }

          // do not display the status message until all ajax calls are complete
          if(++files_added == files.length) {
            var total_files = files.length > 1 ? files.length + ' files were' : '1 file was';
            display_uploader_message(total_files + " added to your queue.  You can create folders, add additional files, or begin the upload by clicking the 'Upload' button.");
          }
        }
      });
    });

		up.refresh(); // Reposition Flash/Silverlight
	});

  // include S3 specific parameters built with the ajax calls done earlier
  uploader.bind('BeforeUpload', function(up, file) {
    up.settings.multipart_params = {
      key: params[file.id]['key'],
      filename: params[file.id]['key'],
      AWSAccessKeyId: params[file.id]['access_key_id'],
      policy: params[file.id]['policy'],
      signature: params[file.id]['signature'],
      acl: 'private',
      success_action_status: '201'
    };

    // update progress meter with new filename
    var message = "<div id='s3_uploader'>";
    message += "<span id='s3_uploader_label'><strong>Uploading:</strong></span>";
    message += "<span id='s3_uploader_file'>"+ file.name +"</span><span id='s3_uploader_percent'>0%</span>";
    message += "<div id='s3_progress_bar'><div></div></div>";
    message += "</div>";
    display_uploader_message(message);
    up.refresh();
  });

  // update progress meter
	uploader.bind('UploadProgress', function(up, file) {
    // stupid IE wants to start with 'NaN%' completed files...
    if(!isNaN(file.percent)) {
      var percent = file.percent + "%";
      $('#s3_uploader_percent').html(percent);
      $('#s3_progress_bar > div').width(percent);
    }

    up.refresh();
	});

  // mark files as fully uploaded
	uploader.bind('FileUploaded', function(up, file) {
    $('#s3_uploader_percent').html('100%');
    $('#s3_progress_bar > div').width('100%');

    // disable removal handler in case of overly creative users
    $('#' + file.id).parents('div.s3_row').find('div.s3_upload_delete > img').off('click');
    up.refresh();
	});

  // display status messages when the upload is completed
  uploader.bind('UploadComplete', function(up, files) {
    if($('#s3_messages > div').hasClass('s3_upload_error')) {
      display_uploader_message("One or more files failed to upload.  Contact your system administrator for support.");
    } else {
      if(Drupal.settings.s3_uploader.view == 'browser_embed') {
        // reload file browser if this upload came from an embedded uploader
        display_uploader_message("Upload Complete!");
        browser_ajax_list(Drupal.settings.s3_uploader.token + '/' + Drupal.settings.s3_uploader.prefix, '#s3_browser');

        $('div.s3_uploads').fadeOut('fast', function() {
          $(this).html("<span class='s3_empty'>No files selected</span>");
          $(this).attr('id', 's3_active');
          $(this).fadeIn('fast');
          up.splice();
        });
      } else {
        display_uploader_message("Upload Complete!<br>Click the 'Save' button at the bottom of this page to submit the filepost.");
        $('#edit-title').removeAttr('disabled');
      }
    }

    up.refresh();
  });

  // react to errors during upload
	uploader.bind('Error', function(up, err) {
    if(typeof err.file.name !== 'undefined') {
      $('#s3_messages').append("<div class='s3_upload_error'>Error: " + err.file.name + " could not be uploaded.</div>");
    }

    if(typeof err.file.id !== 'undefined') {
      $('#' + err.file.id).parents('div.s3_row').find('div.s3_upload_delete > img').off('click');
      $('#' + err.file.id).css('color', 'red');
    }

		up.refresh(); // Reposition Flash/Silverlight
	});

  uploader.refresh();
});