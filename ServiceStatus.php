<?php

namespace SweetCode\StatusAPI;

abstract class ServiceStatus {

    /**
     * No outtage. No performance issues. Great. :)
     */
    const OPERATIONAL           = 1;

    /**
     * Performance issues. - @TODO: Currently only if the service responses slowly.
     */
    const PERFORMANCE_ISSUES    = 2;

    /**
     * If the service is not available for 120 seconds.
     */
    const PARTIAL_OUTAGE        = 3;

    /**
     * If the service is not available for 301 seconds or more.
     */
    const MAJOR_OUTAGE          = 4;

    public static function byDowntime($seconds) {

        if($seconds >= 120 && $seconds <= 300) {
            return self::PARTIAL_OUTAGE;
        } else if($seconds >= 301) {
            return self::MAJOR_OUTAGE;
        }

        return self::OPERATIONAL;

    }

    public static function toName($index) {

        switch ($index) {

            case 1: return 'Operational';
            case 2: return 'Performance Issue';
            case 3: return 'Partial Outage';
            case 4: return 'Major Outage';

            default: return null;

        }

    }

}