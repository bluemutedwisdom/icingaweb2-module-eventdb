<?php
/* Icinga Web 2 | (c) 2016 Icinga Development Team | GPLv2+ */

namespace Icinga\Module\Eventdb\Controllers;

use Icinga\Data\Filter\Filter;
use Icinga\Module\Eventdb\EventdbController;
use Icinga\Module\Eventdb\Forms\Event\EventCommentForm;
use Icinga\Module\Eventdb\Forms\Events\AckFilterForm;
use Icinga\Module\Eventdb\Forms\Events\SeverityFilterForm;
use Icinga\Util\StringHelper;
use Icinga\Web\Url;

class EventsController extends EventdbController
{
    public function init()
    {
        parent::init();
        $this->view->title = 'EventDB: ' . $this->translate('Events');
    }

    public function indexAction()
    {
        $this->assertPermission('eventdb/events');

        $this->getTabs()->add('events', array(
            'active'    => true,
            'title'     => $this->translate('Events'),
            'url'       => Url::fromRequest()
        ));

        $staticQueryColumns = array(
            'ack',
            'id',
            'priority',
            'type',
            'host_name',
            'host_address',
            'created'
        );

        if (! $this->params->has('columns')) {
            $displayColumns = $this->Config('columns');
            if ($displayColumns->isEmpty()) {
                $displayColumns = array(
                    'host_name',
                    'message',
                    'program',
                    'facility'
                );
            } else {
                $displayColumns = $displayColumns->keys();
            }
        } else {
            $displayColumns = StringHelper::trimSplit($this->params->get('columns'));
        }

        $queryColumns = array_merge($staticQueryColumns, array_diff($displayColumns, $staticQueryColumns));

        $events = $this->getDb()
            ->select()
            ->from('event', $queryColumns);

        $events->applyFilter(Filter::matchAny(array_map(
            '\Icinga\Data\Filter\Filter::fromQueryString',
            $this->getRestrictions('eventdb/events/filter', 'eventdb/events')
        )));

        $this->setupPaginationControl($events);

        $this->setupFilterControl(
            $events,
            array(
                'host_name'     => $this->translate('Host'),
                'host_address'  => $this->translate('Host Address'),
                'type'          => $this->translate('Type'),
                'program'       => $this->translate('Program'),
                'facility'      => $this->translate('Facility'),
                'priority'      => $this->translate('Priority'),
                'message'       => $this->translate('Message'),
                'ack'           => $this->translate('Acknowledged'),
                'created'       => $this->translate('Created')
            ),
            array('host_name'),
            array('columns', 'format')
        );

        $this->setupLimitControl();

        $this->setupSortControl(
            array(
                'host_name'     => $this->translate('Host'),
                'host_address'  => $this->translate('Host Address'),
                'type'          => $this->translate('Type'),
                'program'       => $this->translate('Program'),
                'facility'      => $this->translate('Facility'),
                'priority'      => $this->translate('Priority'),
                'message'       => $this->translate('Message'),
                'ack'           => $this->translate('Acknowledged'),
                'created'       => $this->translate('Created')
            ),
            $events,
            array('created' => 'desc')
        );

        if ($this->params->get('format') === 'sql') {
            echo '<pre>'
                . htmlspecialchars(wordwrap($events))
                . '</pre>';
            exit;
        }

        $this->setAutorefreshInterval(15);

        $severityFilterForm = new SeverityFilterForm();
        $severityFilterForm->handleRequest();

        $ackFilterForm = new AckFilterForm();
        $ackFilterForm->handleRequest();

        $this->view->ackFilterForm = $ackFilterForm;
        $this->view->columnConfig = $this->Config('columns');
        $this->view->displayColumns = $displayColumns;
        $this->view->events = $events;
        $this->view->severityFilterForm = $severityFilterForm;
    }

    public function detailsAction()
    {
        $this->assertPermission('eventdb/events');

        $this->getTabs()->add('events', array(
            'title' => $this->translate('Events'),
            'url'   => Url::fromRequest()
        ))->activate('events');

        $columns = array(
            'ack',
            'id',
            'priority',
            'type',
            'host_name',
            'host_address',
            'created',
            'message',
            'program',
            'facility'
        );

        $events = $this->getDb()
            ->select()
            ->from('event', $columns);

        $filter = Filter::fromQueryString($this->getRequest()->getUrl()->getQueryString());
        $events->applyFilter($filter);

        $events->applyFilter(Filter::matchAny(array_map(
            '\Icinga\Data\Filter\Filter::fromQueryString',
            $this->getRestrictions('eventdb/events/filter', 'eventdb/events')
        )));

        $commentForm = null;
        if ($this->hasPermission('eventdb/interact')) {
            $commentForm = new EventCommentForm();
            $commentForm
                ->setDb($this->getDb())
                ->setFilter($filter)
                ->handleRequest();
            $this->view->commentForm = $commentForm;
        }

        $this->view->events = $events->fetchAll();
        $this->view->columnConfig = $this->Config('columns');
    }
}
