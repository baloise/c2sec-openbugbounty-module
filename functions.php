<?php
/*
    OPENBUGBOUNTY C2SEC MODULE

    For generell functions
*/
namespace obb;

define('URL_SPLIT_LENGTH',6);

function error($msg){
    /*
    Returns json encoded error message
    */
    return json_encode(array("ERROR"=>$msg));
}

function extract_attribute($array,$attribute){
    /*
        Returns the attribute 'attribute' from each object in one array
    */
    return array_map(function($o) use ($attribute){return $o->{$attribute};}, $array); 
}

?>
