<?php

namespace SweetCode\StatusAPI;

class StatusAPI {

    // All services ending with this part are NOT a HTTP service
    private static $_UNIVERSE = '.universe.robertsspaceindustries.com';
    private static $_UNIVERSE_LENGTH = 36;

    /**
     * @var array
     */
    private $config;

    /**
     * @var \PDO
     */
    private $pdo;

    public function __construct($config) {

        if(!(file_exists($config)) || !(substr($config, -4) === '.ini')) {
            throw new \InvalidArgumentException('Invalid config file provided.');
        }

        $this->config = parse_ini_file($config, true);

        if(
            // do the sections exist?
            !array_key_exists('database', $this->config) ||
            !array_key_exists('API', $this->config) ||

            // database keys
            !array_key_exists('host', $this->config['database']) ||
            !array_key_exists('name', $this->config['database']) ||
            !array_key_exists('user', $this->config['database']) ||
            !array_key_exists('password', $this->config['database']) ||

            // API keys
            !array_key_exists('endpoint', $this->config['API']) ||
            !array_key_exists('key', $this->config['API'])
        ) {
            throw new \InvalidArgumentException('The provided config doesn\'t contain all required values.');
        }

        $this->pdo = new \PDO(
            'mysql:host=' . $this->config['database']['host'] . ';dbname=' . $this->config['database']['name'] . ';charset=UTF8',
            $this->config['database']['user'],
            $this->config['database']['password']
        );

        if($this->pdo->errorCode() == '0000') {
            throw new \Exception('Failed to connect to the database.');
        }

    }

    /**
     * Runs custom loop to update the data.
     */
    public function run() {

        // metrics
        $webseedAvg = 0;
        $webseedCount = 0;

        $websiteAvg = 0;
        $publicUniverseAvg = 0;

        // get all components
        $components = $this->getComponents();

        // delta time
        $lastIteration = time();

        foreach ($components as $c) {

            // easier access
            $id = $c['id'];
            $name = $c['name'];
            $link = $c['link'];
            $status = intval($c['status']);
            $enabled = $c['enabled'];
            $timeDiff = $c['timeDiff'];
            $downtime = $c['downtime'];

            // if
            //  the component is not enabled/active
            // then skip it
            if (!($enabled)) {
                continue;
            }

            $available = false;
            $responseTime = 0;

            // In this special case we have to types of services:
            //  1. A normal HTTP service, we can just use curl to check if it is available or not
            //  2. Something special, plain TCP, we just gonna ping it with fping @TODO find a better solution

            // if
            //  the service is just a normal HTTP service (can be determinated by the host)
            // then run curl check
            if (!(substr($link, -self::$_UNIVERSE_LENGTH) === self::$_UNIVERSE)) {

                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $link,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_NOBODY => true,
                    CURLOPT_TIMEOUT => 10,
                ));
                curl_exec($curl);

                // @see https://curl.haxx.se/libcurl/c/libcurl-errors.html
                // 0 -> CURLE_OK - All fine.
                $available = (curl_errno($curl) === 0);
                $responseTime = curl_getinfo($curl, CURLINFO_TOTAL_TIME);

            }
            // we are a non-HTTP service, use fping, but it is also not a AWS (Amazon's Web Service)
            else {

                // cachet forces me to store all links with HTTP(s) at the beginnig,
                // we need to get rid of this to use it as a valid host adress to ping at.
                $host = substr($link, 7);

                // ping
                // -c 1 -> only one cycle
                // -s   -> all details
                exec('fping -c 1 -s ' . $host . ' 2>&1', $output, $result);

                // we need to get rid of old data in strdout; the length of "one" entry is 19 entries in one array (already tested that)
                $data = array_slice($output, -19);

                // special case: the PTU servers are not available to the public, we have to use another way to validate if they are online or not
                if($host === 'ptu.universe.robertsspaceindustries.com') {
                    $available = (trim($data[8])=== '1 timeouts (waiting for response)');
                } else {
                    $available = (trim($data[5]) === '1 alive');
                }

                $responseTime = doubleval(substr(trim($data[17]), 0, 4));

            }

            // collecting some data for the metrics
            if($available) {

                $downtime = 0;

                // update metrics points
                // if
                //  the name starts with Webseed
                // then we have 1 of 64 webseed services
                if(substr($name, 0, 7) === 'Webseed') {
                    $webseedAvg = (($webseedAvg * $webseedCount) + $responseTime) / ($webseedCount + 1);
                    $webseedCount++;
                }
                // if
                //  the name is Website
                // then we update the metrics data for the website
                else if($name === 'Website') {
                    $websiteAvg = $responseTime;
                }
                // if
                //  the name is Public Universe
                // then we update the data for the Public Universe
                else if($name === 'Public Universe') {
                    $publicUniverseAvg = $responseTime;
                }

            }
            // if
            //  the service is not available
            // then we have to update the downtime
            else {
                // timeDiff     -> Time since the last status update
                // deltaTime    -> Time between timeDiff and now
                $downtime += ($timeDiff + (time() - $lastIteration));
                $lastIteration = time();
            }

            // determine the next status based on its downtime
            $nextStatus = ServiceStatus::byDowntime($downtime);

            // if
            //  the next status IS OPERATIONAL
            //  the response time is EQUALS OR HIGHER than 8 seconds, we have a extremly slow response
            // then we have some PERFORMANCE_ISSUES with the services
            if(
                $nextStatus === ServiceStatus::OPERATIONAL &&
                $responseTime >= 8
            ) {
                $this->updateStatus($id, ServiceStatus::PERFORMANCE_ISSUES, $status, $responseTime);
            }
            // if
            //  this is not the case
            // just perform a normal update and let the update method handle the rest
            else {
                $this->updateStatus($id, $nextStatus, $status, $downtime);
            }

        }

        // Adding the metrics points
        // 1 -> Webseed Metrics
        // 2 -> Website Metrics
        // 3 -> Public Universe Metrics
        // Multiply everything with 1000 to get the time in ms.
        $this->addMetricsPoint(1, $webseedAvg * 1000);
        $this->addMetricsPoint(2, $websiteAvg * 1000);
        $this->addMetricsPoint(3, $publicUniverseAvg * 1000);

    }

    /**
     * Runs a query to get all components (aka. services).
     *
     * @return \PDOStatement
     */
    public function getComponents() {

        return $this->pdo->query(
            'SELECT `id`, `name`, `link`, `status`, `enabled`, TIME_TO_SEC(TIMEDIFF(NOW(), `updated_at`)) AS `timeDiff`, `downtime` FROM `components`;'
        );

    }

    /**
     * Updates the status of a component and also manages the related incidents.
     *
     * @param int $component The component id.
     * @param \SweetCode\StatusAPI\ServiceStatus $status The current status of the component.
     * @param \SweetCode\StatusAPI\ServiceStatus $prevStatus The previous status of the component.
     * @param double $time If the status IS NOT OPERATIONAL but PERFORMANCE_ISSUES this is the response time of the service if it IS NOT OPERATIONAL and not
     *                     PERFORMANCE_ISSUES it is the delta time that will be added to the current downtime
     */
    public function updateStatus($component, $status, $prevStatus, $time)
    {

        // update component -> set the new status & update the downtime if necessary
        $stmt = $this->pdo->prepare(
            'UPDATE `components` SET `status` = :status, `downtime` = :downtime, `updated_at` = NOW() WHERE `id` = :component;'
        );
        $stmt->execute(array(
            ':component'    => $component,
            ':status'       => $status,
            ':downtime'     => ($status === ServiceStatus::PERFORMANCE_ISSUES ? 0 : $time)
        ));

        // Dealing with incidents now...
        // checking if one incident is still open/cooldown mode
        $stmt = $this->pdo->prepare(
            'SELECT `status`, TIME_TO_SEC(TIMEDIFF(NOW(), `updated_at`)) AS `cooldownTime` FROM `incidents` WHERE `component_id` = :component AND `status` = 3;'
        );
        $stmt->execute(array(
            ':component' => $component
        ));


        $inCooldown = $stmt->rowCount() === 1;
        $cooldownTime = ($inCooldown ? intval($stmt->fetch()['cooldownTime']) : 0);

        // If
        //  the current status is NOT OPERATIONAL
        //  but the next status is and
        //  we are not in a cooldown phase then we have to create a new incident
        // then is the service not available.
        if (
            !($status === ServiceStatus::OPERATIONAL) &&
            $prevStatus === ServiceStatus::OPERATIONAL &&
            !($inCooldown)
        ) {

            // default status title
            $statusTitle = 'Identified: ' . ServiceStatus::toName($status);

            // default status message
            $message = 'The service is currently not available.';

            // if the service is just bad performing...
            if ($status === ServiceStatus::PERFORMANCE_ISSUES) {
                $message = 'The service is currently responsding slowly: ' . number_format($time, 2) . 's.';
            }

            // status 2 -> open
            $stmt = $this->pdo->prepare(
                'INSERT INTO `incidents` (`component_id`, `name`, `status`, `visible`, `message`, `created_at`, `updated_at`) VALUES (:id, :statusTitle, 2, TRUE, :message, NOW(), NOW());'
            );
            $stmt->execute(array(
                ':id' => $component,
                ':statusTitle' => $statusTitle,
                ':message' => $message
            ));

        }
        // If
        //  the current status IS OPERATIONAL
        //  the previous status IS NOT OPERATIONAL
        // then has the issue been resolved -> going into the cooldown mode
        else if(
            $status === ServiceStatus::OPERATIONAL &&
            !($prevStatus === ServiceStatus::OPERATIONAL)
        ) {

            // status 2 -> open
            // status 3 -> watching (aka. cooldown)
            $stmt = $this->pdo->prepare(
                'UPDATE `incidents` SET `status` = 3, `updated_at` = NOW() WHERE `component_id` = :id AND `status` = 2;'
            );
            $stmt->execute(array(
                ':id'       => $component,
            ));

        }
        // If
        //  the current status IS OPERATIONAL
        //  the previous status IS OPERATIONAL
        //  the component is currently in cooldown mode
        //  the cooldown is at least cooling (lmao) for 600 seconds (10 minutes) by now
        // then the issue has been resolved AND did not occure in the 10 minute time frame.
        else if(
            $status === ServiceStatus::OPERATIONAL &&
            $prevStatus === ServiceStatus::OPERATIONAL &&
            $inCooldown &&
            $cooldownTime >= 600
        ) {

            // status 4 -> issue fixed
            // status 3 -> watching (aka. cooldown)
            $stmt = $this->pdo->prepare(
                'UPDATE `incidents` SET `status` = 4, `message` = :message, `updated_at` = NOW() WHERE `component_id` = :id AND `status` = 3;'
            );
            $stmt->execute(array(
                ':id'       => $component,
                ':message'  => 'The issue has been resolved and the service is now available.',
            ));

        }
        // If
        //  the component is currently in cooldown mode
        //  the current status IS NOT OPERATIONAL
        // then the issue occured agin.
        else if(
            !($status === ServiceStatus::OPERATIONAL) &&
            $inCooldown
        ) {

            // status 3 -> watching (aka. cooldown)
            // We are updating it to prevent it from going into the cooldown mode.
            $stmt = $this->pdo->prepare(
                'UPDATE `incidents` SET `updated_at` = NOW(), `status` = 2 WHERE `component_id` = :id AND `status` = 3;'
            );
            $stmt->execute(array(
                ':id' => $component,
            ));

        }

    }

    /**
     * Adds a new dataset value to the provided metric.
     *
     * @param $metric The ID of the metric the value belongs to.
     * @param $value The value.
     */
    public function addMetricsPoint($metric, $value) {

        $stmt = $this->pdo->prepare(
            'INSERT INTO `metric_points` (`metric_id`, `value`, `created_at`, `updated_at`) VALUES (:id, :value, NOW(), NOW());'
        );
        $stmt->execute(array(
            ':id'       => $metric,
            ':value'    => $value,
        ));

    }

}