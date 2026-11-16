<?php

namespace SiteShifter\Transfer;

/**
 * The few HTTP calls cloud storages need. The plugin answers them with the
 * WordPress HTTP API, the CLI with curl, the tests with a fake.
 */
interface HttpClient
{
    /**
     * @param string                 $method GET, POST, PUT, PATCH, DELETE
     * @param array<string,string>   $headers
     * @param string|array|null      $body a string, or ['file' => path, 'offset' => int, 'length' => int] to send part of a file
     * @param string|null            $toFile write the response body to this file instead of returning it
     * @return array{code:int,headers:array<string,string>,body:string} headers lower-cased; code 0 = no answer
     */
    public function request($method, $url, array $headers = [], $body = null, $toFile = null);
}
