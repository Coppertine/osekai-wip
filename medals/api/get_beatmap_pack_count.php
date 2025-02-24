<?php
require_once($_SERVER['DOCUMENT_ROOT'] . "/global/php/functions.php");

error_reporting(E_ERROR);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

function CalculateCount($id)
{
    $req = Database::execSelect("SELECT * FROM MedalsBeatmapPacks WHERE Id = ?", "s", [$id]);
    if (count($req) == 0) {
        $page = file_get_contents("https://osu.ppy.sh/beatmaps/packs/" . $id);
        $count = substr_count($page, '<li class="beatmap-pack-items__set">');
        $ids = [];
        preg_match_all("/href=\"https:\/\/osu.ppy.sh\/beatmapsets\/(.+)\" class/", $page, $ids);
        $ids = $ids[1];

        Database::execOperation("INSERT INTO `MedalsBeatmapPacks` (`Id`, `Count`, `Ids`)
        VALUES (?, ?, ?);", "sis", [$id, $count, json_encode($ids)]);
    }
    LengthCalculation_QueueAll();
}

$queue = [];
function LengthCalculation_Queue($id)
{
    global $queue;
    if (count(Database::execSelect("SELECT * FROM BeatmapLengths WHERE Id = ?", "i", [$id])) == 0) {
        $queue[] = $id;
        Database::execOperation("INSERT INTO `BeatmapLengths` (`Id`, `Length`)
        VALUES (?, ?);", "ii", [$id, 0]);
    }
}

function LengthCalculation_QueueAll()
{
    $packs = Database::execSimpleSelect("SELECT * FROM MedalsBeatmapPacks");
    $already_calculated_raw = Database::execSimpleSelect("SELECT * FROM BeatmapLengths");
    $already_calculated = [];
    foreach ($already_calculated_raw as $bm) {
        $already_calculated[] = $bm['Id'];
    }
    foreach ($packs as $pack) {
        $beatmaps = json_decode($pack['Ids']);
        foreach ($beatmaps as $beatmap) {
            if (!in_array($beatmap, $already_calculated)) {
                LengthCalculation_Queue($beatmap);
            }
        }
    }
}

function LengthCalculation_Calculate()
{
    global $queue;
    foreach ($queue as $id) {
        echo "Getting data for " . $id . "<br>";
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "https://osu.ppy.sh/api/get_beatmaps?k=" . OSU_API_V1_KEY . "&s=" . $id);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = json_decode(curl_exec($curl), true);
        curl_close($curl);
        if (intval($output[0]['total_length']) == 0) {
            echo "Could not get data.";
            continue;
        }
        echo "Got " . intval($output[0]['total_length']) . "<br>";
        Database::execOperation("UPDATE `BeatmapLengths` SET `Length` = ? WHERE `Id` = ? LIMIT 1;", "ii", [intval($output[0]['total_length']), $id]);
    }
}

function GetPack($id)
{
    // TODO: Caching?
    $pack = Database::execSelect("SELECT * FROM MedalsBeatmapPacks WHERE Id = ?", "s", [$id])[0];
    $beatmaps = json_decode($pack['Ids']);
    $return = [];
    foreach ($beatmaps as $beatmap) {
        $return[] = Database::execSelect("SELECT * FROM BeatmapLengths WHERE Id = ?", "i", [$beatmap])[0];
    }
    return $return;
}

if (isset($_GET['id'])) {
    $req = $_GET['id'];
    $requests = explode(",", $req);
    $returnVal = [];
    foreach ($requests as $request) {
        CalculateCount($request);
        $returnVal[] = GetPack($request);
    }
    ob_start();
    echo json_encode($returnVal);
    $size = ob_get_length();
    header("Content-Encoding: none");
    header("Content-Length: {$size}");
    header("Connection: close");
    ob_end_flush();
    @ob_flush();
    flush();
    
    LengthCalculation_Calculate(); // might be a few unprocessed packs in there...
}

if (isset($_GET['updateLengths'])) {
    if (isset($_GET['id'])) {
        $req = $_GET['id'];
        $requests = explode(",", $req);
        foreach ($requests as $request) {
            LengthCalculation_Queue($request);
        }
    } else {
        LengthCalculation_QueueAll();
    }
    LengthCalculation_Calculate();
}