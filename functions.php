<?php
namespace obb;

define('URL_SPLIT_LENGTH',6);

function error($msg){
    /*
    Returns json encoded error message
    */
    return json_encode(array("ERROR"=>$msg));
}

?>
