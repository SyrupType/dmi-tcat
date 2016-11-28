<?php

if ($argc < 1)
    die; // only run from command line
// ----- params -----
set_time_limit(0);
error_reporting(E_ALL);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../common/constants.php';
require_once __DIR__ . '/../../common/functions.php';
require_once __DIR__ . '/../../capture/common/functions.php';
require_once __DIR__ . '/../../capture/ids/lookup.php';
require_once __DIR__ . '/../../capture/common/tmhOAuth/tmhOAuth.php';


// DEFINE LOOKUP PARAMETERS HERE

$bin_name = '';            // name of the bin

if (empty($bin_name))
    die("bin_name not set\n");

if (dbserver_has_utf8mb4_support() == false) {
    die("DMI-TCAT requires at least MySQL version 5.5.3 - please upgrade your server\n");
}

$querybin_id = queryManagerBinExists($bin_name);

// ----- connection -----
$dbh = pdo_connect();
create_bin($bin_name, $dbh);
print("Connected to the database.\n");


// lancer script python et attendre qu'il ait fini
print("Start of scrapping process.\n");
if(file_exists('ids.todo')) {
    exec('mv ids.todo ids.tmp');
}
else {
    /* select all tweets ids and authors */
    $query = "select from_user_name, id from $bin_name" . '_tweets';
    $rec = $dbh->prepare($query);
    $rec->execute();
    $results = $rec->fetchAll();
    $fp = fopen('ids.tmp', 'w');
    foreach ($results as $f => $v) {
        fwrite($fp, $v['id'] . ',"' . $v['from_user_name'] . "\"\n");
        print('.');
    }
    fclose($fp);
    print("\n");
}
exec('python3 scrapping.py');
while (file_exists('ids.tmp')) {
    sleep(10);
}

// format json : {"tweet_id": {"likes": [], "replies": []}}
if(file_exists('ids.json')) {
    print("End of scrapping process.\n");
    $handle = fopen("ids.json", "r");
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $observed_at = strftime("%Y-%m-%d %H-%M-%S", date('U'));
            $json = json_decode($line,TRUE);
            foreach ($json as $tweet_id => $lists) {
                // likes
                if (count($lists['likes']) > 0) {
                    $tr = new TwitterLike($tweet_id, $lists['likes'], $observed_at);
                    $tr->save($dbh, $bin_name);
                }
                // replies
                if (count($lists['replies']) > 0) {
                    search($lists['replies']);
                }
            }
        }
        fclose($handle);
        exec('rm ids.json');
    } else {
        print "Error opening the data file ids.json. Now stopping.\n";
        exit(1);
    }


}
else {
    print "Error during the scrapping process. Now stopping.\n";
    exit(1);
}

print "\n" . str_repeat("=", 83) . "\n\n";


function get_tweet_url_from_id($tweet_id) {
    global $twitter_keys, $current_key, $retries, $i;

    $keyinfo = getRESTKey(0);
    $current_key = $keyinfo['key'];
    $ratefree = $keyinfo['remaining'];

    print "\ncurrent key $current_key ratefree $ratefree\n";

    $tmhOAuth = new tmhOAuth(array(
        'consumer_key' => $twitter_keys[$current_key]['twitter_consumer_key'],
        'consumer_secret' => $twitter_keys[$current_key]['twitter_consumer_secret'],
        'token' => $twitter_keys[$current_key]['twitter_user_token'],
        'secret' => $twitter_keys[$current_key]['twitter_user_secret'],
    ));

    if ($ratefree <= 0 || $ratefree % 10 == 0) {
        print "\n";
        $keyinfo = getRESTKey($current_key);
        $current_key = $keyinfo['key'];
        $ratefree = $keyinfo['remaining'];
        $tmhOAuth = new tmhOAuth(array(
            'consumer_key' => $twitter_keys[$current_key]['twitter_consumer_key'],
            'consumer_secret' => $twitter_keys[$current_key]['twitter_consumer_secret'],
            'token' => $twitter_keys[$current_key]['twitter_user_token'],
            'secret' => $twitter_keys[$current_key]['twitter_user_secret'],
        ));
    }

    $params = array(
        'id' => $tweet_id,
        'include_entities' => 'false',
        'trim_user' => 'true',
    );

    $code = $tmhOAuth->user_request(array(
        'method' => 'GET',
        'url' => $tmhOAuth->url('1.1/statuses/show'),
        'params' => $params
    ));

    $ratefree--;

    $reset_connection = false;

    if ($tmhOAuth->response['code'] == 200) {
        $data = json_decode($tmhOAuth->response['response'], true);

        if (is_array($data) && empty($data)) {
            return($data['url']);
        }

    } else if ($retries < 4 && $tmhOAuth->response['code'] == 503) {
        /* this indicates problems on the Twitter side, such as overcapacity. we slow down and retry the connection */
        print "!";
        sleep(7);
        $i--;  // rewind
        $retries++;
        $reset_connection = true;
    } else if ($retries < 4) {
        print "\n";
        print "Failure with code " . $tmhOAuth->response['response']['code'] . "\n";
        var_dump($tmhOAuth->response['response']['info']);
        var_dump($tmhOAuth->response['response']['error']);
        var_dump($tmhOAuth->response['response']['errno']);
        print "The above error may not be permanent. We will sleep and retry the request.\n";
        sleep(7);
        $i--;  // rewind
        $retries++;
        $reset_connection = true;
    } else {
        print "\n";
        print "Permanent error when querying the Twitter API. Please investigate the error output. Now stopping.\n";
        exit(1);
    }

    if ($reset_connection) {
        $tmhOAuth = new tmhOAuth(array(
            'consumer_key' => $twitter_keys[$current_key]['twitter_consumer_key'],
            'consumer_secret' => $twitter_keys[$current_key]['twitter_consumer_secret'],
            'token' => $twitter_keys[$current_key]['twitter_user_token'],
            'secret' => $twitter_keys[$current_key]['twitter_user_secret'],
        ));
        $reset_connection = false;
    }
}

?>