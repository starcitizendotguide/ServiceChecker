<?php

namespace SweetCode\StatusAPI;


abstract class ServiceType {

    const WEBSITE = 0.1;
    const FORUMS  = 0.2;

    const WEBSEED = 1;

    const MANIFEST = 2;

    const PUBLIC_UNIVERSE_SERVICE = 3.1;

    const PUBLIC_TEST_UNIVERSE_WEBSITE = 4.1;
    const PUBLIC_TEST_UNIVERSE_SERVICE = 4.2;

    const LAUNCHER = 5;

    const UNKNOWN = -1;

    public static function identify($link) {

        if(!(is_string($link))) {
            return self::UNKNOWN;
        }

        switch (strtolower($link)) {

            // WEBSITE
            case preg_match('/(http(s)?:\/\/robertsspaceindustries\.com)/', $link, $matches) && count($matches):
                return self::WEBSITE;

            // FORUMS
            case preg_match('/(http(s)?:\/\/forums\.robertsspaceindustries\.com)/', $link, $matches) && count($matches):
                return self::FORUMS;

            // WEBSEED
            case preg_match('/([0-9]+\.webseed\.robertsspaceindustries\.com)/', $link, $matches) && count($matches):
                return self::WEBSEED;

            // MANIFEST
            case preg_match('/(http(s)?:\/\/manifest\.robertsspaceindustries\.com)/', $link, $matches) && count($matches):
                return self::MANIFEST;

            // PUBLIC UNIVERSE SERVICE
            case preg_match('/(public\.universe\.robertsspaceindustries\.com)/', $link, $matches) && count($matches):
                return self::PUBLIC_UNIVERSE_SERVICE;

            // PUBLIC TEST UNIVERSE WEBSITE
            case preg_match('/(http(s)?:\/\/ptu\.cloudimperiumgames\.com)/', $link, $matches) && count($matches):
                return self::PUBLIC_TEST_UNIVERSE_WEBSITE;

            // PUBLIC TEST UNIVERSE SERVICE
            case preg_match('/(ptu\.universe\.robertsspaceindustries\.com)/', $link, $matches) && count($matches):
                return self::PUBLIC_TEST_UNIVERSE_SERVICE;

            // LAUNCHER
            case preg_match('/(launcher[0-9]+\.robertsspaceindustries\.com)/', $link, $matches) && count($matches):
                return self::LAUNCHER;

            // DEFAULT
            default:
                return self::UNKNOWN;

        }

    }

}