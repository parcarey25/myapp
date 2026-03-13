<?php
if (function_exists('imagecreatetruecolor')) {
    echo "GD is enabled!";
} else {
    echo "GD is NOT enabled.";
}