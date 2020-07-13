#!/usr/bin/env php
<?php

/**
 * @author Ammar Faizi <ammarfaizi2@gmail.com>
 * @license MIT
 * @version 0.0.1
 */

const COOKIE_FILE = "/tmp/cookie_tea_subdomain.txt";

$try = 0;

if (!isset($argv[1])) {
  ex_err("Usage: ./".$argv[0]." <domain>");
}

$targetDomain = $argv[1];

@unlink(COOKIE_FILE);

/**
 * Visiting target to get cookie and CSRF data.
 */
$o = curl("https://subdomainfinder.c99.nl/")["out"];

/**
 * Take the string inside of <form> tag.
 */
if (!preg_match("/<form.+?method=\"POST\".+?>(.+)<\/form>/si", $o, $m)) {
  ex_err("Cannot find form element");
}

/**
 * Get the hidden inputs inside of <form> tag.
 * This is where the CSRF(s) are stored.
 */
if (!preg_match_all("/<input[^\\<\\>]+?type=\"hidden\"[^\\<\\>]+?>/si", $m[1], $mm)) {
  ex_err("Cannot find hidden input elements!");
}

$postData = [];

/**
 * Collect all hidden input.
 */
foreach ($mm[0] as $k => $v) {
  $x = preg_match("/name=\"([^\\\\\"]+)\"/", $v, $mmx);
  if ($x) {
    preg_match("/value=\"([^\\\\\"]+)\"/", $v, $mmy) or $mmy[1] = "";
    $postData[hee($mmx[1])] = hee($mmy[1]);
  }
}
$postData["domain"] = $targetDomain;
$postData["scan_subdomains"] = "";
$postData["privatequery"] = "on";


/**
 * Handle dynamic CSRF.
 */
if (!preg_match("/<script src=\"(.+?)\" crossorigin=\"\"><\/script>/", $o, $mm)) {
  ex_err("Cannot find dynamic CSRF handler!");
}


/**
 * Plug HTTPS protocol.
 */
if (substr($mm[1], 0, 2) === "//") {
  $mm[1] = "https:".$mm[1];
}

/**
 * Retrieve dynamic javascript CSRF handler.
 */
$o2 = curl($mm[1], [CURLOPT_REFERER => "https://subdomainfinder.c99.nl/"])["out"];

/**
 * Take dynamic CSRF endpoint.
 */
if (!preg_match("/fetch\(\"(.+?)\"\)/", $o2, $mmm)) {
  ex_err("Cannot find dynamic API handler!");
}

/**
 * Visit dynamic CSRF endpoint.
 */
$o3 = json_decode(
  curl(
    "https://subdomainfinder.c99.nl/".ltrim($mmm[1], "/"),
    [CURLOPT_REFERER => "https://subdomainfinder.c99.nl/"]
  )["out"],
  true
);

$ex = explode("let selectedItems = document.getElementsByName(\"", $o2, 2);
if (count($ex) < 2) {
  ex_err("Cannot get target name from dynamic CSRF");
}
$ex = explode("\"", $ex[1], 2)[0];

if (!isset($postData[$ex])) {
  ex_err("Cannot find post data with the same key as dynamic CSRF");
}

if (!preg_match("/selectedItems\\.forEach\\((.+?)\\}\\);/s", $o2, $mmx)) {
  ex_err("Cannot find data to be in for each");
}

$ey = explode("item.setAttribute(\"name\", \"", $mmx[1], 2);
if (count($ey) < 2) {
  ex_err("Cannot find attribute to be changed");
}
$ey = explode(");", $ey[1], 2)[0];


if (!preg_match("/(CSRF\d+).+?data\\.([a-f0-9]+).+?\"(.+)\"/", $ey, $mmx)) {
  ex_err("Cannot find dynamic CSRF data.");
}

$oldVal = $postData[$ex];
unset($postData[$ex]);
$postData[$mmx[1].$o3[$mmx[2]].$mmx[3]] = $oldVal;
$postData["jn"] = "JS aan, T aangeroepen, CSRF aangepast";

retry:
// Must wait 3 seconds to prevent CSRF error.
sleep(3);

$o = curl("https://subdomainfinder.c99.nl/",
  [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($postData),
    CURLOPT_REFERER => "https://subdomainfinder.c99.nl/"
  ]
)["out"];

if (!preg_match_all(
  "/<tr>.*?<td[^\\<\\>].+?checkStatus\\('(.+?)'.+?geoip.php\\?ip=(.+?)'.+?title='CloudFlare is (.+?)'/s",
  $o, $mmy)) {

  if ($try < 3) {
    goto retry;
  }

  ex_err("Cannot get subdomain list!");
}

if (!count($mmy[1])) {
  echo "We found domain list boundary, but there is no result in the given boundary!\n";
  exit(0);
}

$data = [];
echo "[\n";
foreach ($mmy[1] as $k => $v) {
  echo ($k ? "," : "")."\n  ".json_encode([
    "r" => $v,
    "ip" => $mmy[2][$k],
    "cloudflare" => $mmy[3][$k]
  ]);
}
echo "\n]\n";
@unlink(COOKIE_FILE);


/**
 * @param string $str
 */
function ex_err($str)
{
  echo $str."\n";
  exit(1);
}

/**
 * @param string $str
 * @return string
 */
function hee($str)
{
  return html_entity_decode($str, ENT_QUOTES, "UTF-8");
}


/** 
 * @param string $url
 * @param array  $opt
 * @return array
 */
function curl($url, $opt = [])
{

  $optf = [
    CURLOPT_COOKIEJAR => COOKIE_FILE,
    CURLOPT_COOKIEFILE => COOKIE_FILE,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:78.0) Gecko/20100101 Firefox/78.0",
  ];

  foreach ($opt as $k => $v) {
    $optf[$k] = $v;
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, $optf);
  $o = [
    "out" => curl_exec($ch),
    "info" => curl_getinfo($ch)
  ];
  $err = curl_error($ch);
  $ern = curl_errno($ch);

  if ($err) {
    ex_err("Curl Error: ({$ern}) {$err}");
  }

  curl_close($ch);
  return $o;
}
