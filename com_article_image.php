<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'com_article_image';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.1.0';
$plugin['author'] = 'Textpattern Community';
$plugin['author_uri'] = 'https://github.com/textpattern';
$plugin['description'] = 'Article image helper on the Textpattern Write panel';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '4';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '3';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@prefs
#@language en, en-gb, en-us
com_article_image => Article image helper
com_article_image_external => External image format
com_article_image_internal => Internal image format
com_article_image_limit => Maximum number of images
#@article
com_article_image_dropzone => Click or drop files on this zone
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * com_article_image
 *
 * A Textpattern CMS plugin for inserting/uploading article images:
 *  -> Upload images directly in the Write panel
 *  -> Drag/drop images from Txp/your computer/other web pages
 *  -> Drag images directly into article fields to insert/upload
 *
 * @author Textpattern Community
 * @link   https://github.com.com/textpattern
 */
if (txpinterface === 'admin') {
    new com_article_image();
}

class com_article_image
{
    protected $event = 'com_article_image';
    protected $version = '0.1.0';
    protected $privs = '1,2,3,4,5,6';

    /**
     * Constructor
     */
    public function __construct()
    {
        global $event;

        add_privs('plugin_prefs.'.$this->event, $this->privs);
        add_privs($this->event, $this->privs);
        add_privs('prefs.'.$this->event, $this->privs);

        register_callback(array($this, 'prefs'), 'plugin_prefs.'.$this->event);
        register_callback(array($this, 'install'), 'plugin_lifecycle.'.$this->event);
        register_callback(array($this, 'head'), 'admin_side', 'head_end');

        if ($event === 'article' && has_privs('image.edit.own')) {
            register_callback(array($this, 'upload'), 'article_ui', 'article_image');
            register_callback(array($this, 'save'), 'article_posted');
            register_callback(array($this, 'save'), 'article_saved');
            register_callback(array($this, 'head'), 'admin_side', 'head_end');
            register_callback(array($this, 'js'), 'admin_side', 'body_end');
        }

        register_callback(array($this, 'post_upload'), 'site.update', 'image_uploaded');

        $this->install();
    }

    /**
     * Installs prefs if not already defined.
     *
     * @param string $evt Admin-side event
     * @param string $stp Admin-side step
     */
    public function install($evt = '', $stp = '')
    {
        if ($stp == 'deleted') {
            // Remove predecessor abc_article_image prefs too.
            safe_delete('txp_prefs', "name LIKE 'abc\_file\_%' AND name LIKE 'com\_article\_image\_%'");
        } elseif ($stp == 'installed') {
            safe_update('txp_prefs', "event='".$this->event."'", "name LIKE 'com\_article\_image\_%'");

            if (get_pref('com_article_image_limit', null) === null)
                set_pref('com_article_image_limit', 12, $this->event, PREF_PLUGIN, 'text_input', 300, PREF_PRIVATE);
            if (get_pref('com_article_image_internal', null) === null)
                set_pref('com_article_image_internal', '<txp:image id="{#}" />', $this->event, PREF_PLUGIN, 'longtext_input', 500, PREF_PRIVATE);
            if (get_pref('com_article_image_external', null) === null)
                set_pref('com_article_image_external', '', $this->event, PREF_PLUGIN, 'longtext_input', 700, PREF_PRIVATE);
        }
    }

    /**
     * Redirect to the preferences panel
     */
    public function prefs()
    {
        header('Location: ?event=prefs#prefs_group_com_article_image');
        echo
            '<p id="message">'.n.
            '   <a href="?event=prefs#prefs_group_com_article_image">'.gTxt('continue').'</a>'.n.
            '</p>';
    }

    /**
     * Inject style rules.
     *
     * @return string CSS style block
     */
    public function head()
    {
        echo <<<EOCSS
<style>
    #txp-image-group-content img {max-width:100%;height:auto}
    #txp-image-group-content .sortable {position:relative}
    #txp-image-group-content .destroy {position:absolute;right:0;z-index:100;visibility:hidden}
    #txp-image-group-content .sortable .destroy {visibility:visible}
    #article-file-reset {visibility:hidden}
    #article-file-container p, #article-file-select p {display:inline-block}
    #article-file-input {height: 100%; width: 100%; z-index: 50; position: absolute; opacity: 0}
    #article-file-drop>div.txp-form-field-value {position: relative; outline: 1px solid #e3e3e3; min-height: 5ex; overflow:hidden; text-align:center}
    #main_content {position:sticky;top:0}
    .hidden {display:none}
</style>
EOCSS;
    }

    /**
     * Inject the JavaScript
     *
     * @return string HTML &lt;script&gt; tag
     */
    public function js()
    {
        global $img_dir;

        $internal_tag = escape_js(get_pref('com_article_image_internal', '<txp:image id="{#}" />'));
        $external_tag = escape_js(get_pref('com_article_image_external'));
        $img_location = escape_js(ihu.$img_dir);

        echo script_js(<<<EOJS
function comArticleImageFormat(format, data) {
  var ids = [], text = "";
  if (format.match(/\{##\}/)) {
    data.forEach(function(img) {
      ids.push(img.id);
    });
    text = format.replace(/\{##\}/g, ids.join(","));
  } else data.forEach(function(img) {
      let chunk = img.id ? format.replace(/\{#\}/g, img.id) : format;
      for (let [key, value] of Object.entries(img)) if (typeof value == "undefined") {
        chunk = chunk.replace(new RegExp("\\s+\\w+=[\"']{"+key+"}[\"']", "g"), "").replace(new RegExp("{"+key+"}", "g"), "");
      } else {
        let val = value.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        chunk = chunk.replace(new RegExp("{"+key+"}", "g"), val)
      }
      text += chunk;
    });
  return text;
}

function readfiles(files, input) {
var formData = new FormData(), count = 0;

for (var i = 0; i < files.length; i++) {
    if (files[i].type.match(/^image\//)) {
        formData.append("thefile[]", files[i]);
        count++;
    }
}

var text = "Upload "+count+" image"+(count == 1 ? "" : "s")+"?";
if (!count || !window.confirm(text)) return 0;

formData.append("_txp_token", textpattern._txp_token);
$(input).prop("disabled", true);

$.ajax({
    url: "index.php?event=image&step=image_insert&app_mode=async",
    type: "POST",
    data: formData,
    async: true,
    success: function (data) {
        var text = typeof comArticleImage == "undefined" ? "" : comArticleImageFormat(imageTag, comArticleImage);
        textpattern.Relay.data.fileid = comArticleImage = [];
        $(input).prop("disabled", false);
        insertAtCursor(input, text);
        textpattern.Console.announce("uploadEnd");
    },
    cache: false,
    contentType: false,
    processData: false
 });
return count;
}

$("#body, #excerpt").on("dragover", function(evt) {
    e = evt.originalEvent;
    if (e.dataTransfer.types.includes("Files")) {
        e.stopPropagation();
        e.preventDefault();
        e.dataTransfer.dropEffect = "copy";
    }
}).on("drop", function(evt) {
    var e = evt.originalEvent, count = 0
    if (e.dataTransfer.files.length) {//console.log(e.dataTransfer.files)
        count = readfiles(e.dataTransfer.files, this);
    }
    if (count)
        e.preventDefault();
    else {
        var img = $("<div>"+e.dataTransfer.getData("text/html")+"</div>").find("img");
        var text = "";
        if (img.length) {
          img.each(function( index ) {
            var me = $(this);
            if (me.attr("src") || me.attr("srcset")) {
              var atts = {
                src: me.attr("src"),
                srcset: me.attr("srcset"),
                sizes: me.attr("sizes"),
                alt: me.attr("alt"),
                title: me.attr("title"),
                h: me.attr("height"),
                w: me.attr("width")
              };
              if (imageLink) {
                text += comArticleImageFormat(imageLink, [atts]);
              }
              else {
                var tmpimg = $("<img />").attr(atts);
                text += tmpimg.prop("outerHTML");
              }
            }
          });
        }
        if (text || !this.setRangeText) {
          e.preventDefault();
          insertAtCursor(this, text || e.dataTransfer.getData("text/plain"));
        }
    }
});

$("#txp-image-group-content").on("click", "#article-file-reset", function(e) {
    e.preventDefault();
    $("#article-file-input").val("");
    $("#article-file-preview").empty();
    $("#article-file-reset").css("visibility", "hidden");
}).on("click", ".sortable .destroy", function(e) {
    e.preventDefault();
    $(this).parent().removeClass("sortable").clone().appendTo("#article-file-select");
    $(this).parent().remove();
    $("#txp-image-group-content").trigger("sortupdate").sortable("refresh");
}).on("click", "#article-file-add", function(e) {
    e.preventDefault();
    $(this).children("span").toggleClass("ui-icon-arrowthickstop-1-s ui-icon-arrowthickstop-1-n");
    $("#article-file-select").toggleClass("hidden");
}).on("dragstart", "#article-file-container a, #article-file-select a", function(e) {
//      console.log(e.originalEvent)
      var dragged = e.originalEvent.dataTransfer.getData("text/html") || e.originalEvent.target;
        var imgs = $(dragged).find("img");
        var text = "", data = [];
        imgs.each(function() {
        data.push({alt: $(this).attr("alt"), id: $(this).data("id"), w: $(this).data("width"), h: $(this).data("height"), src: imageDir+"/"+$(this).data("id")+$(this).data("ext")});
      })
      text = comArticleImageFormat(imageTag, data);
        e.originalEvent.dataTransfer.setData("text/plain", text);
        e.originalEvent.dataTransfer.setData("text/html", "");
}).on("sortupdate", function( event ) {
    var myContainer = $("#article-file-container"), list = [];
    myContainer.children("p.sortable").each(function() {
        list.push($(this).data("id"));
    });
    $("#article-image").val(list.join());
}).on("click", "#article-file-select a", function(e) {
    e.preventDefault();
    $(this).parent().addClass("sortable").appendTo("#article-file-container");
    $("#txp-image-group-content").trigger("sortupdate").sortable("refresh");
});

$("#txp-image-group-content").on("dragend", "#article-file-container a", function(e) {
    $("#txp-image-group-content").sortable("enable");
}).sortable({
    items: ".sortable",
    out: function (event, ui) {
        if (!!droppedOutside) $(this).sortable("disable");
    },
    over: function (event, ui) {
        $(this).sortable("enable");
    },
    beforeStop: function (event, ui) {droppedOutside = false;
    },
    start: function (event, ui) {droppedOutside = true;
    }
});

function insertAtCursor (input, textToInsert) {
  const isSuccess = document.execCommand("insertText", false, textToInsert);
  if (isSuccess) return;

  // IE 8-10
  if (document.selection) {
    const ieRange = document.selection.createRange();
    ieRange.text = textToInsert;

    // Move cursor after the inserted text
    ieRange.collapse(false /* to the end */);
    ieRange.select();

    return;
  }

  // Firefox (non-standard method)
  if (typeof input.setRangeText === "function") {
    const start = input.selectionStart;
    input.setRangeText(textToInsert);
    // update cursor to be at the end of insertion
    input.selectionStart = input.selectionEnd = start + textToInsert.length;

  } else if (input.selectionStart || input.selectionStart == '0') {
      var startPos = input.selectionStart;
      var endPos = input.selectionEnd;
      input.value = input.value.substring(0, startPos)
          + textToInsert
          + input.value.substring(endPos, input.value.length);
      input.selectionStart = startPos + textToInsert.length;
      input.selectionEnd = startPos + textToInsert.length;
  } else {
      input.value += textToInsert;
  }
    // Notify any possible listeners of the change
//    const e = document.createEvent("UIEvent");
//    e.initEvent("input", true, false);
    const e = new Event("input", {"bubbles":true, "cancelable":false});
    input.dispatchEvent(e);
}

window.comArticleImagePreview = function (input) {
    var createObjectURL = (window.URL || window.webkitURL || {}).createObjectURL

    if (createObjectURL && input.files.length) {
        $("#article-file-preview").empty();
        $("#article-file-reset").css("visibility", "visible");
        $(input.files).each(function () {
            $("#article-file-preview").append("<p><img src=\'" + createObjectURL(this) + "\' /></p>");
        });
    }
}

var imageTag = "{$internal_tag}",
    imageLink = "{$external_tag}",
    imageDir = "{$img_location}";
EOJS
        , false, array('article'));
    }

    /**
     * Send script response after each upload completes
     *
     * @param string $evt Admin-side event
     * @param string $stp Admin-side step
     * @param array  $rs  Uploaded image metadata record
     */
    public function post_upload($evt, $stp, $rs)
    {
        global $img_dir;

        $img = array_intersect_key($rs, array(
            'id'  => null,
            'alt' => null,
            'h'   => null,
            'w'   => null))
        + array(
            'src' => ihu.$img_dir.'/'.$rs['id'].$rs['ext']
        );

        send_script_response('comArticleImage = ['.json_encode($img).'].concat(typeof comArticleImage == "undefined" ? [] : comArticleImage)');
    }

    /**
     * Alter the upload form markup to include the image thumbs and dropzone
     *
     * @param  string $evt  Admin-side event
     * @param  string $stp  Admin-side step
     * @param  array  $data Existing upload form markup
     * @param  array  $rs   Uploaded image metadata record
     * @return string       HTML
     */
    public function upload($evt, $stp, $data, $rs)
    {
        $ids = $images = array();
        $fields = 'id, name, ext, thumbnail, alt, h, w';

        if (!empty($rs['Image'])) {
            $images = array();

            foreach (do_list_unique($rs['Image']) as $id) {
                if (!is_numeric($id))
                    $id = (int) fetch('id', 'txp_image', 'name', $id);
                if ($id) {
                    $ids[] = $id;
                }
            }

            $id_list = implode(',', $ids);
            $rows = $ids ? safe_rows($fields, 'txp_image', 'id IN ('.$id_list.') ORDER BY FIELD(id, '.$id_list.')') : array();

            foreach ($rows as $row) {
                extract($row);

                $images[] = '<p class="sortable" data-id="'.$id.'"><a href="index.php?event=image&step=image_edit&id='.$id.'" title="'.txpspecialchars($name).' ('.$id.')">'
                .'<img src="'.imagesrcurl($id, $ext, $thumbnail).'" data-id="'.$id.'" data-ext="'.$ext.'" data-width="'.$w.'" data-height="'.$h.'" alt="'.txpspecialchars($alt).'" />'
                .'</a><button class="destroy"><span class="ui-icon ui-icon-close">'.gTxt('delete').'</span></button></p>';
            }
        }

        $article_image = '<div id="article-file-container">'.implode(n, $images).'</div>'.n;

        $images = array();
        $select_images = '';
        $limit = intval(get_pref('com_article_image_limit')) or $limit = 12;

        $rows = safe_rows($fields, 'txp_image', ($ids ? 'id NOT IN('.$id_list.')' : '1').' ORDER BY date DESC LIMIT '.$limit);

        foreach ($rows as $row) {
            extract($row);

            $images[] = '<p data-id="'.$id.'"><a href="index.php?event=image&step=image_edit&id='.$id.'" title="'.txpspecialchars($name).' ('.$id.')">'
                .'<img src="'.imagesrcurl($id, $ext, $thumbnail).'" data-id="'.$id.'" data-ext="'.$ext.'" data-width="'.$w.'" data-height="'.$h.'" alt="'.txpspecialchars($alt).'" />'//image(array('id' => $id))
                .'</a><button class="destroy"><span class="ui-icon ui-icon-close">'.gTxt('delete').'</span></button></p>';
        }

        $select_images = '<p class="txp-actions">'
            .'<a id="article-file-add" href="#article-file-select">'.gTxt('add')
            .'<span class="ui-icon ui-icon-arrowthickstop-1-s"></span></a></p>'.n
        .'<div id="article-file-select" class="hidden">'.implode(n, $images).'</div>'.n;

        return $data.n.$article_image.n.'<hr />'.n
        .inputLabel(
            'article-file-input',
            '<button id="article-file-reset" class="destroy"><span class="ui-icon ui-icon-close">'.gTxt('delete').'</span></button>'.n.
            '<input id="article-file-input" type="file" name="article_file[]" multiple="multiple" accept="image/*" onchange="comArticleImagePreview(this)" />'
            .'<p class="secondary-text">'.gTxt('com_article_image_dropzone').'</p>'.n
            .'<div id="article-file-preview"></div>'.n, gTxt('upload'),
            array('', 'instructions_article_image'),
            array('id' => 'article-file-drop', 'class' => 'txp-form-field article-image')
        ).n.$select_images;
    }

    /**
     * [save description]
     * @param string $evt Admin-side event
     * @param string $stp Admin-side step
     * @param array  $rs  Uploaded image metadata record
     */
    public function save($evt, $stp, $rs)
    {
        if (empty($_FILES['article_file']['tmp_name'][0]) || !has_privs('image.edit')) {
            return;
        }

        include_once 'lib'.DIRECTORY_SEPARATOR.'class.thumb.php';

        $ids = array();
        $files = Txp::get('\Textpattern\Server\Files')->refactor($_FILES['article_file']);

        foreach ($files as $file) {
            $meta = array('alt' => $file['name']);  // @todo: caption, category?
            $img_result = image_data($file, $meta);

            if (is_array($img_result)) {
                list($message, $id) = $img_result;
                $ids[] = $id;
            }
        }

        $GLOBALS['ID'] = intval($rs['ID']);
        $ids = implode(',', $ids);
        $ids = implode(',', do_list_unique($rs['Image'].','.$ids));

        safe_update('textpattern', "Image='".doSlash($ids)."'", 'ID='.$GLOBALS['ID']);
    }
}

# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. Overview

Assists with article image management on the @Write@ panel.

# --- END PLUGIN HELP ---
-->
<?php
}
?>
