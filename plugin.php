<?php

/**
 * Plugin Name: Advanced Importer
 * Plugin URI: https://misterbr.ir/
 * Description: Import links from CSV file
 * Version: 1.3
 * Author: MisterBR
 * Author URI: https://misterbr.ir/
 */

// No direct call
if (!defined('YOURLS_ABSPATH')) die();

global $exist_links;

// Register your plugin admin page
yourls_add_action('plugins_loaded', 'myplugin_init');
function myplugin_init()
{
    yourls_register_plugin_page('mrbr_importer', 'Advanced Importer', 'mrbr_importer_display_page');
}

// The function that will draw the admin page
function mrbr_importer_display_page()
{
    if (strpos(YOURLS_SITE, 'https://') !== false) {
        $site = str_replace('https://', '', YOURLS_SITE);
    } else {
        $site = str_replace('http://', '', YOURLS_SITE);
    }
?>
    <style>
        .plugin_page_mrbr_importer.desktop div#wrap div {
            margin-top: 31px;
            border-radius: 7px;
        }

        .plugin_page_mrbr_importer.desktop div#wrap div div#msg {
            margin-left: 10px;
            padding: 1px
        }
    </style>
    <?php
    global $exist_links;

    if ($exist_links != NULL && count($exist_links) > 0) {
        echo '<div class="error"><div id="msg"><b>These links already exist in the database (Total: ' . count($exist_links) . '):</b><br />';
        foreach ($exist_links as $link) {
            echo '<span>' . $link . '</span><br /> ';
        }
        echo '</div></div>';
    }

    ?>

    <h2>Advanced Importer</h2>
    <div class="tips">
        <p>CSV file must have 3 columns: <strong>Title</strong>, <strong>Mobile</strong>, <strong>UTM</strong> <br />
            Sample: <a href="<?php echo YOURLS_SITE; ?>/user/plugins/mrbr-importer/csv/sample.csv">sample.csv</a>
            (<b style="color:red">Don't forget to delete the first row</b>)
        </p>
    </div>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="site" id="site" required value="<?php echo $site; ?>">
        <p><label for="prefix">PrefixURl:</label> <input type="text" name="prefix" id="prefix"></p>
        <p><label for="file">Select a file:</label> <input type="file" name="file" id="file" required></p>
        <p><input type="submit" name="submit_mrbr" value="Upload"></p>
    </form>
<?php

}

yourls_add_action('plugins_loaded', 'mrbr_importer_handle_form');

function mrbr_importer_handle_form()
{
    if ($_POST && $_FILES && isset($_POST['submit_mrbr'])) {

        global $exist_links;

        if (!isset($_POST['site']) || !isset($_FILES['file'])) {
            die('Invalid request');
            return;
        }

        $website = $_POST['site'];
        $prefix = $_POST['prefix'];
        $file = $_FILES['file'];
        $path = YOURLS_ABSPATH . '/user/plugins/mrbr-importer/csv/';
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $maxUploadSizeBytes = intval(ini_get('upload_max_filesize')) * 1024 * 1024;

        // Delete old files (bank.csv)
        if (file_exists($path . 'bank.csv')) {
            unlink($path . 'bank.csv');
        }
        // Delete old files (data.csv)
        if (file_exists($path . 'data.csv')) {
            unlink($path . 'data.csv');
        }

        if ($ext != 'csv' || $file['size'] > $maxUploadSizeBytes) {
            // Show error message on the admin page
            echo '<div class="error"><p>';
            if ($ext != 'csv') {
                echo 'File is not CSV. ';
            }
            if ($file['size'] > $maxUploadSizeBytes) {
                echo 'File is too big.';
            }
            echo '</p></div>';
            return;
        }


        // Move the uploaded file into the csv folder
        if (!move_uploaded_file($file['tmp_name'], $path . 'bank.csv')) {
            echo '<div class="error"><p>Failed to move uploaded file.</p></div>';
            return;
        }

        if (file_exists($path . 'bank.csv')) {
            // Open the input file
            $inputFile = fopen($path . 'bank.csv', 'r');

            // Create the output file
            mrbr_utf8_fopen_write($path . 'data.csv');
            $outputFile = fopen($path . 'data.csv', 'a');
        } else {
            echo '<div class="error"><p>File not found.</p></div>';
            return;
        }


        // Process each row of the input file
        $rowCount = 9999999;
        while (($row = fgetcsv($inputFile)) !== false) {
            //// Skip the first row
            // if ($rowCount++ == 0) {
            //     continue;
            // }

            // Apply mb_convert_encoding to each field in the row
            foreach ($row as &$field) {
                $field = mb_convert_encoding($field, 'UTF-8', 'auto');
            }

            $title = $row[1];
            $url = $row[2];
            $sh_link = $prefix ?  $prefix . $rowCount :  $rowCount;
            $link =  $website . ($prefix ? '/' . $prefix . $rowCount : '/' . $rowCount);
            $row[3] =  str_replace('//', '/', $link);
            $shor_link =  str_replace('//', '/', $sh_link);


            // Write the modified row to the output file
            fputcsv($outputFile, $row);

            // Insert link to database
            mrbr_insert_link_to_db($title, $url, $shor_link);
            $rowCount++;
        }

        // Close the input and output files
        fclose($inputFile);
        fclose($outputFile);

        // Delete the input file
        unlink($path . 'bank.csv');

        // Show success message on the admin page
        if ($exist_links != NULL && ($rowCount - 1) >= count($exist_links)) {
            return yourls_add_notice('<div id="msg"><p>All links already exist in the database.</p></div>', 'warning');
        } else {
            return yourls_add_notice('<div id="msg"><p>File uploaded successfully.</p><p><a href="' . YOURLS_SITE . '/user/plugins/mrbr-importer/csv/data.csv">Download CSV file</a></p></div>', 'success');
        }
    }
}


function mrbr_utf8_fopen_write($filename)
{
    $fopen_flags = 'w+';
    $exists = file_exists($filename);
    if ($exists) {
        $file = fopen($filename, "r");
        $UTF8content = fread($file, filesize($filename));
        fclose($file);
        if (!mb_check_encoding($UTF8content, 'UTF-8') || !($UTF8content === file_get_contents($filename))) {
            unlink($filename);
            $exists = false;
        }
    }
    if (!$exists) {
        $file = fopen($filename, $fopen_flags);
        fwrite($file, "\xEF\xBB\xBF"); // UTF-8 BOM
        fclose($file);
    }
}


function  mrbr_insert_link_to_db($title, $url, $shor_link)
{
    global $ydb;
    global $exist_links;

    $table = YOURLS_DB_TABLE_URL;
    $ip = yourls_get_IP();
    $clicks = 0;
    $timestamp = date('Y-m-d H:i:s');

    // check exist link
    $_sql = "SELECT * FROM `$table` WHERE `keyword` = :keyword";
    $_bind = array(
        'keyword' => $shor_link
    );

    if ($ydb->fetchObjects($_sql, $_bind)) {
        $exist_links[] = $url;
    } else {
        $sql = "INSERT INTO `$table` (`keyword`, `url`, `title`, `timestamp`, `ip`, `clicks`) VALUES (:keyword, :url, :title, :timestamp, :ip, :clicks)";
        $bind = array(
            'keyword' => $shor_link,
            'url' => $url,
            'title' => $title,
            'timestamp' => $timestamp,
            'ip' => $ip,
            'clicks' => $clicks
        );
        $ydb->fetchAffected($sql, $bind);
    }
}