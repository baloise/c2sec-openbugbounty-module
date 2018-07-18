<?php
/**
 * OPENBUGBOUNTY C2SEC MODULE
 *
 * For general functions, exceptions, constants
 */
namespace obb;

/**
 * Prepended to log entry
 */
define('NAME','OBB Module');

/**
 * Validation to retrieve the numerical id from the URL
 */
define('URL_SPLIT_LENGTH',6);

/**
 * Location of the configuration file
 */
define('CONFIG','./obb.ini');

/**
 * DateTime string which counts as an invalid / not-set date
 */
define('INVALID_DATE','1970-01-01 00:00:01');

/**
 * Number of entries which are saved at one to the database, when updating/populating it
 */
define('BULK_SIZE',50);

/**
 * Number of how often the connection is tried to established if it failed.
 */
define('CONNECTION_RETRIES',10);


/**
 * Returns the ID from a given URL (openbugbounty API)
 * @param string $url
 * @throws XMLFormatException if the data cannot be processed / the API changed
 * @throws FormatException if the URL is not accessible
 * @return int ID
 */
function get_id($url){
    $url_split = preg_split("/\//",$url);
    if(sizeof($url_split) != URL_SPLIT_LENGTH){
        throw new FormatException("URL format seems to be false." . $url);
    }
    $id = $url_split[sizeof($url_split)-2];
    if(!is_numeric($id)){
        throw new FormatException("URL format seems to be false, ID is not a number: " . $id);
    }
    return $id;
}

/**
 * Log and throws excception
 * @param Exception $exception
 * @throws Exception
 */
function handle_exception($exception){
    syslog(LOG_ERR, get_class($exception) . " " . $exception->getMessage());
    throw $exception;
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
