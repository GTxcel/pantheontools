#!/usr/bin/env php
<?php
date_default_timezone_set('America/New_York');
/*
  Example:
  ./plugin-status.php 'abc@xyz.com,def@xyz.com' by-site dev
  ./plugin-status.php 'abc@xyz.com' by-plugin dev
 Optional parameters:
 --only-sites='<list of sites>'
 --exclude-sites='<list of sites>'
 --only-plugin='<list of plugins>'
 --exclude-plugin='<list of plugins>'
 --org=<organization name, default value is XYZ>
 --tag=<tag>
*/

$test = new CheckPlugin_Command();
array_shift($_SERVER['argv']);
$emails = array_shift($_SERVER['argv']);
$group = array_shift($_SERVER['argv']);
$env = array_shift($_SERVER['argv']);
$params = prepare($_SERVER['argv']);

$test->run($emails, $group, $env, $params);

class CheckPlugin_Command
{
    function run($emails, $group, $env, $params)
    {
        $emails = explode(',', $emails);
        $org = isset($params['--org']) ? implode(",", $params['--org']) : 'XYZ';
        $tag = isset($params['--tag']) ? "--tag=".implode(",", $params['--tag']) : '';

        exec("terminus organizations sites list --format=json --org={$org} {$tag}", $listOfSite);
        $listOfSite = json_decode($listOfSite[0]);
        if (isset($params['--only-sites'])) {
            $newList = [];
            foreach ($listOfSite as $site) {
                if (in_array($site->name, $params['--only-sites'])) {
                    $newList[] = $site;
                }
            }
            $listOfSite = $newList;
        }
        if (isset($params['--exclude-sites'])) {
            $newList = [];
            foreach ($listOfSite as $site) {
                if (!in_array($site->name, $params['--exclude-sites'])) {
                    $newList[] = $site;
                }
            }
            $listOfSite = $newList;
        }
        echo 'Find ' . count($listOfSite) . " sites \n";

        $result = [];
        foreach ($listOfSite as $site) {
            if ($site->framework == 'wordpress') {
                echo 'Parse plugins from ' . $site->name . " \n";
                $result[$site->name] = $this->plugins($site->name, $env, $params);
            }
        }

        if ($group == 'by-site') {
            $report = $this->createReportBySite($result);
        } else {
            $report = $this->createReportByPlugins($result);
        }

        $subject = 'Report ' . date('m-d-Y');
        foreach ($emails as $email) {
            echo "Sent report to " . $email . " \n";
            mail($email, $subject, $report, 'Content-type: text/html;');
        }
    }

    private function plugins($url, $env, $params)
    {
        exec("terminus wp 'plugin list --format=json --fields=\"name,status,update,version,update_version\"' --site={$url} --env={$env}", $result);
        if (isset($result[0])) {
            $result = json_decode($result[0]);
            if (isset($params['--only-plugin'])) {
                $newList = [];
                if(count($result)) {
                    foreach ($result as $plugin) {
                        if (in_array($plugin->name, $params['--only-plugin'])) {
                            $newList[] = $plugin;
                        }
                    }
                }
                $result = $newList;
            }
            if (isset($params['--exclude-plugin'])) {
                $newList = [];
                foreach ($result as $plugin) {
                    if (!in_array($plugin->name, $params['--exclude-plugin'])) {
                        $newList[] = $plugin;
                    }
                }
                $result = $newList;
            }
            return $result;
        } else return [];
    }

    private function groupBySite($data)
    {
        $result = [];
        if (empty($data)) return $result;
        foreach ($data as $plugin) {
            $result[$plugin->status][$plugin->update][] = $plugin;
        }
        return $result;
    }

    private function groupByPlugin($data, $site, $result)
    {
        if (empty($data)) return $result;
        foreach ($data as $plugin) {
            if ($plugin->update == 'available') {
                $result[$plugin->name]['update'] = $plugin->update_version;
            }
            $result[$plugin->name]['versions'][$plugin->version][] = $site;
        }
        return $result;
    }

    private function createReportBySite($data)
    {
        $result = '';
        foreach ($data as $site => $byStatus) {
            $byStatus = $this->groupBySite($byStatus);
            $result .= "<h1>" . $site . "</h1>";
            foreach ($byStatus as $status => $byUpdate) {
                $result .= '<h3>' . $status . "</h3><table border='1' width='100%'>";
                foreach ($byUpdate as $updateNeed => $pluginList) {
                    $result .= '<tr><td>Update status: ' . $updateNeed . "</td><td>";
                    foreach ($pluginList as $plugin) {
                        $result .= $plugin->name . ' [' . $plugin->version . ((!empty($plugin->update_version)) ? " => " . $plugin->update_version : '') . "]<br>";
                    }
                    $result .= "</td></tr>";
                }
                $result .= '</table>';
            }
        }

        return $result;
    }

    private function createReportByPlugins($data)
    {
        $plugins = [];
        foreach ($data as $site => $byStatus) {
            $plugins = $this->groupByPlugin($byStatus, $site, $plugins);
        }

        $result = "";
        foreach ($plugins as $pluginName => $plugin) {
            $result .= "<h1><b>{$pluginName}</b>
                        " . (isset($plugin['update']) ? 'update available to v' . $plugin['update'] : '') .
                " max installed version: " . max(array_keys($plugin['versions'])) . "</h1><table border='1' width='100%'>";
            foreach ($plugin['versions'] as $version => $sites) {
                $result .= "<tr><td>{$version}</td><td>" . implode("<br>", $sites) . "</td></tr>";
            }
            $result .= '</table>';
        }
        return $result;
    }
}


function prepare($arr)
{
    $params = [];
    if (count($arr)) {
        foreach ($arr as $p) {
            $param = explode("=", $p);
            if (count($param) == 2) {
                $params[$param[0]] = explode(",", $param[1]);
            }
        }
    }
    return $params;
}