<?php
trapbox_intercept('file_put_contents', function ($original, $filename, $content = null, $flags = null) {
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if ($ext === 'php') {
        file_put_contents('./audit.log', "Hack attempt!\n", FILE_APPEND);
        return true;
    }
    return $original($filename, $content, $flags);
});

var_dump(file_put_contents('app.php', '<?php echo "This is a hack"'));