<?php
/* Icinga Web 2 - EventDB | (c) 2017 Icinga Development Team | GPLv2+ */

namespace Icinga\Module\Eventdb\ProvidedHook\Monitoring;

use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Authentication\Auth;
use Icinga\Data\Filter\Filter;
use Icinga\Data\Filter\FilterParseException;
use Icinga\Exception\InvalidPropertyException;
use Icinga\Module\Eventdb\Data\LegacyFilterParser;
use Icinga\Module\Monitoring\Object\Host;
use Icinga\Module\Monitoring\Object\MonitoredObject;
use Icinga\Module\Monitoring\Object\Service;
use Icinga\Web\Navigation\Navigation;
use Icinga\Web\Url;
use Icinga\Web\UrlParams;

class EventdbActionHook
{
    protected static $wantCache = true;

    protected static $cachedNav = array();

    protected static $customFilters = array();

    public static function wantCache($bool=true)
    {
        static::$wantCache = $bool;
    }

    /**
     * @param MonitoredObject $object
     * @param bool            $no_cache
     *
     * @return null|Filter
     */
    public static function getCustomFilter(MonitoredObject $object)
    {
        if (! Auth::getInstance()->hasPermission('eventdb/events')) {
            return null;
        }

        $objectKey = static::getObjectKey($object);

        // check cache if the filter already have been rendered
        if (static::$wantCache && array_key_exists($objectKey, self::$customFilters)) {
            return self::$customFilters[$objectKey];
        }

        $config = static::config();
        $custom_var = $config->get('custom_var', null);

        $service = null;
        $edb_filter = null;
        if ($custom_var !== null && $object instanceof Service) {
            $edb_filter = $object->{'_service_' . $custom_var . '_filter'};
            $service = $object->service_description;
        } elseif ($custom_var !== null && $object instanceof Host) {
            $edb_filter = $object->{'_host_' . $custom_var . '_filter'};
        }

        $customFilter = null;
        if ($edb_filter !== null) {
            if (LegacyFilterParser::isJsonFilter($edb_filter)) {
                try {
                    $customFilter = LegacyFilterParser::parse(
                        $edb_filter,
                        $object->host_name,
                        $service
                    );
                } catch (InvalidPropertyException $e) {
                    Logger::warning($e->getMessage());
                }
            } else {
                try {
                    $customFilter = Filter::fromQueryString($edb_filter);

                    if (! in_array('host_name', $customFilter->listFilteredColumns())) {
                        $customFilter = $customFilter->andFilter(Filter::expression('host_name', '=', $object->host_name));
                    }
                } catch (FilterParseException $e) {
                    Logger::warning('Could not parse custom EventDB filter: %s (%s)', $edb_filter, $e->getMessage());
                }
            }
        }

        return self::$customFilters[$objectKey] = $customFilter;
    }

    /**
     * @param MonitoredObject $object    Host or Service to render for
     * @param bool            $no_cache  Only for testing - to avoid caching
     *
     * @return array|Navigation
     */
    public static function getActions(MonitoredObject $object)
    {
        if (! Auth::getInstance()->hasPermission('eventdb/events')) {
            return array();
        }

        $objectKey = static::getObjectKey($object);

        // check cache if the buttons already have been rendered
        if (static::$wantCache && array_key_exists($objectKey, self::$cachedNav)) {
            return self::$cachedNav[$objectKey];
        }

        $nav = new Navigation();

        $config = static::config();

        $custom_var = $config->get('custom_var', null);

        $edb_cv = null;
        $always_on = null;

        if ($custom_var !== null && $object instanceof Service) {
            $edb_cv = $object->{'_service_' . $custom_var};
            $always_on = $config->get('always_on_service', 0);
        } elseif ($custom_var !== null && $object instanceof Host) {
            $edb_cv = $object->{'_host_' . $custom_var};
            $always_on = $config->get('always_on_host', 0);
        }

        $customFilter = static::getCustomFilter($object);
        if ($customFilter !== null) {
            $params = UrlParams::fromQueryString($customFilter->toQueryString());
            $nav->addItem(
                'events_filtered',
                array(
                    'label'    => mt('eventdb', 'Filtered events'),
                    'url'      => Url::fromPath('eventdb/events')->setParams($params),
                    'icon'     => 'tasks',
                    'class'    => 'action-link',
                    'priority' => 1
                )
            );
        }

        // show access to all events, if (or)
        // - custom_var is not configured
        // - always_on is configured
        // - custom_var is configured and set on object (to any value)
        if ($custom_var === null || ! empty($edb_cv) || ! empty($always_on)) {
            $nav->addItem(
                'events',
                array(
                    'label'    => mt('eventdb', 'All events for host'),
                    'url'      => Url::fromPath(
                        'eventdb/events',
                        array(
                            'host_name' => $object->host_name,
                        )
                    ),
                    'icon'     => 'tasks',
                    'class'    => 'action-link',
                    'priority' => 99
                )
            );
        }

        return self::$cachedNav[$objectKey] = $nav;
    }

    protected static function getObjectKey(MonitoredObject $object)
    {
        $type = $object->getType();
        $objectKey = sprintf('%!%', $type, $object->host_name);
        if ($type === 'service') {
            $objectKey .= '!' . $object->service_description;
        }
        return $objectKey;
    }

    protected static function config()
    {
        return Config::module('eventdb')->getSection('monitoring');
    }
}
