/**
 *  s3_functions.js
 *  Functions called by either the uploader or browser.
 **/


/**
 * Open or close a folder, while maintaining only one open folder at any time
 * in order not to confuse the uploader.
 **/
function toggle_uploader_folder() {
  var link_span = $(this);
  var s3_row    = $(this).parents('div.s3_row');
  var loader_id = $(this).attr('src');

  // reset the active folder
  $('#s3_active').removeAttr('id');
  if(link_span.hasClass('s3_open')) {
    // folder is closing...
    if(s3_row.parent().hasClass('s3_nested')) {
      // active folder was a subfolder... reset active folder to be its parent
      var s3_row_parent_id = s3_row.parent().attr('id');
      $('span.s3_open[src="'+ s3_row_parent_id +'"]').parents('div.s3_row').attr('id', 's3_active');
    } else {
      // active folder was a top level folder... reset active folder to be root
      $('div.s3_uploads').attr('id', 's3_active');
    }
  } else {
    // folder is opening...
    s3_row.attr('id', 's3_active');
  }

  // toggle s3_open class and close folders not required to display the new active folder
  $('#' + loader_id).fadeToggle('fast', function() {
    link_span.toggleClass('s3_open');

    // remove leading slash, and split along slashes
    var tree_ids = link_span.parent().attr('id');
    var ids      = tree_ids.substr(1, tree_ids.length-1).split('/');
    $.each($('div.s3_uploads').find('div.s3_link > span.s3_open'), function(key, element) {
      var in_tree = false;

      for(var i=0; i < ids.length; i++) {
        if(ids[i] == $(element).attr('src')) {
          in_tree = true;
        }
      }

      if(!in_tree) {
        var subloader_id = '#' + $(element).attr('src');
        $(subloader_id).fadeOut('fast');
        $(element).removeClass('s3_open');
      }
    });
  });
}


/**
 *  Create a row used to display a folder or file within the uploader
 *  @param path   string The path prefix for the given object
 *  @param name   string The name of the folder or file for which the row is being created
 *  @param type   string 'folder' or 'file'
 *  @param size   integer The size of the file for which the row is being created
 *  @param id     string  The ID of a file created by plupload
 **/
function get_uploader_row(path, name, type, size, id) {
  var link_div, loader_div;

  size = typeof size === 'undefined' ? '&nbsp;' : size;
  var size_div   = "<div class='s3_upload_size'>"+ size +"</div>";
  var delete_div = "<div class='s3_upload_delete'><img class='s3_bind' src='/"+ Drupal.settings.s3_uploader.module_path +"/images/delete.gif' alt='Remove'></div>";

  if(type == 'folder') {
    var plaintext = path + '/' + name;
    var hash_code = 0;

    if(plaintext.length > 0) {
      // create a quick and dirty hash code to avoid annoying problems when
      // using S3 keys as CSS classes and IDs
      for(var i = 0; i < plaintext.length; i++) {
        hash_code = ((hash_code<<5)-hash_code) + plaintext.charCodeAt(i);
        hash_code = hash_code & hash_code; // Convert to 32bit integer
      }
    }

    // remove trailing and leading slashes from folder name
    link_div   = "<div id='"+ path + '/' + hash_code + "' class='s3_link'><span class='s3_folder s3_open s3_bind' src='"+ hash_code +"'>" + name + "</span></div>";
    loader_div = "<div class='s3_nested' id='"+ hash_code +"'><span class='s3_empty'>Folder is empty</span></div>";
    return "<div class='s3_row s3_hidden' id='s3_active'>" + link_div + delete_div + "</div>" + loader_div;
  }

  link_div = "<div class='s3_link'><span class='s3_file' id='"+ id +"'>" + name + "</span></div>";
  return "<div class='s3_row s3_hidden'>" + link_div + size_div + delete_div + "</div>";
}


/**
 * Remove a single file from the uploader
 **/
function remove_uploader_file() {
  var s3_row    = $(this).parents('div.s3_row');
  var file_id   = s3_row.find('span.s3_file').attr('id');
  var empty_msg = null;
  var loader    = null;

  if($(this).parents('div.s3_nested').length == 0) {
    loader = $('div.s3_uploads');
    empty_msg = 'No files selected';
  } else {
    loader = $(this).parents('div.s3_nested');
    empty_msg = 'Folder is empty';
  }

  s3_row.fadeOut('fast', function () {
    s3_row.remove();
    if(loader.children().length == 0) {
       loader.html("<span class='s3_empty'>"+ empty_msg +'</span>');
     }
  });

  // remove files from the upload queue
  for(var i=0; i < uploader.files.length; i++) {
    if(uploader.files[i].id == file_id) {
      display_uploader_message(uploader.files[i].name + ' was removed from your queue.');
      uploader.removeFile(uploader.files[i]);
    }
  }
}


/**
 *  Remove a folder from the uploader and any files it might contain
 **/
function remove_uploader_folder() {
  var s3_row        = $(this).parents('div.s3_row');
  var s3_row_parent = s3_row.parent();
  var loader_id     = '#' + s3_row.find('div.s3_link > span').attr('src');
  var folder_name   = s3_row.find('div.s3_link > span').text();

  // remove files from the upload queue
  $.each($(loader_id).find('span.s3_file'), function(key, element) {
    for(var i = 0; i < uploader.files.length; i++) {
      if(uploader.files[i].id == $(element).attr('id')) {
        uploader.removeFile(uploader.files[i]);
      }
    }
  });

  // fade out and/or remove loader
  if(s3_row.find('div.s3_link > span').hasClass('s3_open')) {
    $(loader_id).fadeOut('fast', function() {
      $(loader_id).remove();
    });
  } else {
    $(loader_id).remove();
  }

  // If the active folder, or a folder containing the active folder is being
  // removed, then set its parent as active before removal.
  if(s3_row.attr('id') == 's3_active' || $(loader_id).find('#s3_active').length > 0) {
    if(s3_row.attr('id') == 's3_active') {
      s3_row.removeAttr('id');
    } else {
      $(loader_id).find('#s3_active').removeAttr('id');
    }

    if(s3_row_parent.hasClass('s3_uploads')) {
      // parent is root
      $('div.s3_uploads').attr('id', 's3_active');
    } else {
      // parent is a subfolder
      var parent_src = s3_row.parents('div.s3_nested').attr('id');
      $('span.s3_folder[src="'+ parent_src +'"]').parents('div.s3_row').attr('id', 's3_active');
    }
  }

  // remove folder and restore 'no files selected' dummy row if no other files
  // or folders remain
  s3_row.fadeOut('fast', function () {
    s3_row.remove();

    if($('div.s3_uploads').children().length == 0) {
      $('div.s3_uploads').html("<span class='s3_empty'>No files selected</span>");
    } else {
      if(s3_row_parent.children().length == 0) {
        s3_row_parent.html("<span class='s3_empty'>Folder is empty</span>");
      }
    }
  });

  display_uploader_message('The ' + folder_name + ' folder and all files contained in it were removed from your upload queue.');
}


/**
 *  Helper function to store the accepted file extensions of uploaded files
 **/
function get_uploader_file_filters() {
  var filters = [];

  for(var key in Drupal.settings.s3_uploader.extensions) {
    filters[key] = {title: Drupal.settings.s3_uploader.extensions[key] + ' files', extensions: Drupal.settings.s3_uploader.extensions[key]};
  }

  return filters;
}


/**
 * Display an uploader status message
 * @param message string The message being displayed
 **/
function display_uploader_message(message) {
  if($('#s3_messages > div').hasClass('s3_bonehead')) {
    $('#s3_messages > div.s3_bonehead > span').html(message);
  } else {
    $('#s3_messages').append("<div class='s3_bonehead'><span>" + message + "</span></div>");
  }
}


/**
 * Remove an uploader status message
 **/
function remove_uploader_message() {
  $('#s3_messages > div.s3_bonehead').fadeOut('fast', function() {
    $(this).remove();
  });
}


/**
 * Open/close a folder in the file browser
 * @param folder object The folder being opened/closed
 **/
function toggle_browser_folder(folder) {
  // handle the context change when this function is dynamically binded
  if($.isFunction($(this).hasClass) && ($(this).hasClass('s3_folder'))) {
    folder = $(this);
  }

  var loader = $('#' + folder.parent().attr('src'));
  if(folder.hasClass('s3_open')) {
    loader.fadeOut('fast');
  } else if(loader.find('div.s3_row').length == 0) {
    // open folder and populate it with an ajax call
    loader.show();
    loader.html("<span class='s3_loader'>Loading...</span>");
    browser_ajax_list(folder.attr('src'), '#' + folder.parent().attr('src'));
  } else {
    // folder is already populated, just show it
    loader.fadeIn('fast');
  }

  folder.toggleClass('s3_open');
}


/**
 * Get the files listed in a "folder"
 * @param ajax_params string Parameters passed to the ajax callback function
 * @param parent_id   string The CSS ID used to identify the parent of this "folder"
 **/
function browser_ajax_list(ajax_params, parent_id) {
  $.ajax({
    type: 'GET',
    url: '/s3/js/get/' + Drupal.settings.s3_uploader.nid + '/' + ajax_params,
    cache: false,
    dataType: 'text',
    success: function(object_list) {
      // hide loader dummy row and display the list of objects returned
      $(parent_id).hide();
      $(parent_id).fadeIn('fast', function() {
        $(parent_id).html(object_list);

        // bind click handler to newly created folder and delete links
        $(parent_id).find('span.s3_folder').on('click', toggle_browser_folder);
        $(parent_id).find('div.s3_delete > img').on('click', delete_browser_object);
      });
    }
  });
}


/**
 * Remove an object from the file browser and delete it from S3
 * @param clicked_object object The jQuery representation of the object being removed
 **/
function delete_browser_object(clicked_object) {
  // handle the context change when dynamically binded
  if($.isFunction($(this).parent) && ($(this).parent().hasClass('s3_delete'))) {
    clicked_object = $(this);
  }

  var s3_key    = clicked_object.parent().attr('src');
  var s3_row    = clicked_object.parents('div.s3_row');
  var loader_id = s3_row.children('div.s3_link').attr('src');

  if(confirm('Do you really want to delete this object?')) {
    if(typeof loader_id !== 'undefined') {
      if(s3_row.hasClass('s3_open')) {
        // hide the files listed if the folder is open
        $('div#' + loader_id).fadeOut('fast', function () {
          $('div#' + loader_id).html("<span class='s3_loader'>Deleting...</span>");
          $('div#' + loader_id).show();
        });
      } else {
        $('div#' + loader_id).html("<span class='s3_loader'>Deleting...</span>");
        $('div#' + loader_id).show();
      }
    } else {
      s3_row.fadeTo('fast', 0.5, function() {
        s3_row.children('div.s3_link').html("<span class='s3_loader'>Deleting...</span>");
      });
    }

    $.ajax({
      type: 'GET',
      url: '/s3/js/delete/' + s3_key,
      cache: false,
      dataType: 'text',
      success: function(response) {
        if(typeof loader_id !== 'undefined') {
          $('div#' + loader_id).hide();
        }

        var is_deleted = $.parseJSON(response);
        if(is_deleted) {
          s3_row.fadeOut('fast', function(){
            s3_row.remove();
          });
        } else {
          alert('Failed to delete object.');
        }
      }
    });
  }
}


/**
 * Display messages when people share files
 * @param message string The message to be displayed
 **/
function display_share_message(message) {
  if($('#s3_share_messages > div').hasClass('s3_bonehead')) {
    $('#s3_share_messages > div.s3_bonehead > span').html(message);
  } else {
    $('#s3_share_messages').append("<div class='s3_bonehead'><span>" + message + "</span></div>");
  }
}


/**
 * Remove messages from the file sharing fieldset
 **/
function remove_share_message() {
  $('#s3_share_messages > div.s3_bonehead').fadeOut('fast', function() {
    $(this).remove();
  });
}