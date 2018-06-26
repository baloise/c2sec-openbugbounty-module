<?php
/**
 * OPENBUGBOUNTY C2SEC MODULE
 *
 * For generell functions, classes
 */
namespace obb;

define('URL_SPLIT_LENGTH',6);
define('CONFIG','./obb_config');

/**
 * Returns json encoded error message
 * @param string $msg
 * @return JSON-encoded string
 */
function error($msg){
    return json_encode(array("ERROR"=>$msg));
}

/**
 * Returns the attribute 'attribute' from each object in one array
 * @param array $array
 * @param string $attribute
 * @return array or NULL if the input is invalid
 */
function extract_attribute($array,$attribute){

    if(sizeof($array) == 0 or !isset($array[0]->{$attrubute})){
        return NULL;
    }
    return array_map(function($o) use ($attribute){return $o->{$attribute};}, $array); 
}


/**
 * Custom Exceptions:
 *
 *
 * XMLFormatException
 *
 * Gets thrown if the expected XML structure does not match the real one.
 * This probably means the API has been changed, and this module needs updating.
 */
class XMLFormatException extends \Exception{}

/**
 * ConnectionException
 *
 * Gets thrown if no connection to openbugbounty.org can be established.
 */
class ConnectionException extends \Exception{}

/**
 * FormatException
 *
 * Gets thrown if something else is out of the expected format, for example URLs.
 */
class FormatException extends \Exception{}

/**
 * NoResultException
 *
 * Gets thrown if a search gave no (but valid) result, or if the response body was empty.
 */
class NoResultException extends \Exception{}

/**
 * EncodingException
 *
 * Gets thown if an error occures while enconding into JSON.
 */
class EncodingException extends \Exception{}
?>
