<?php
/**
 * ActiveSync Handler for PING requests
 *
 * Copyright 2009 - 2010 The Horde Project (http://www.horde.org)
 *
 * @author Michael J. Rubinsky <mrubinsk@horde.org>
 * @package Horde_ActiveSync
 */
/**
 * Zarafa Deutschland GmbH, www.zarafaserver.de
 * This file is distributed under GPL v2.
 * Consult LICENSE file for details
 */
class Horde_ActiveSync_Request_Ping extends Horde_ActiveSync_Request_Base
{
    const STATUS_NOCHANGES = 1;
    const STATUS_NEEDSYNC = 2;
    const STATUS_MISSING = 3;
    const STATUS_PROTERROR = 4;
    // Hearbeat out of bounds (TODO)
    const STATUS_HBOUTOFBOUNDS = 5;

    // Requested more then the max folders (TODO)
    const STATUS_MAXFOLDERS = 6;

    // Folder sync is required, hierarchy out of date.
    const STATUS_FOLDERSYNCREQD = 7;
    const STATUS_SERVERERROR = 8;

    // Ping
    const PING = 'Ping:Ping';
    const STATUS = 'Ping:Status';
    const HEARTBEATINTERVAL =  'Ping:HeartbeatInterval';
    const FOLDERS =  'Ping:Folders';
    const FOLDER =  'Ping:Folder';
    const SERVERENTRYID =  'Ping:ServerEntryId';
    const FOLDERTYPE =  'Ping:FolderType';

    /**
     * Handle a PING command from the PIM. Ping is sent periodically by the PIM
     * to tell the server what folders we are interested in monitoring for
     * changes. If no changes are detected by the server during the 'heartbeat'
     * interval, the server sends back a status of 1 to indicate heartbeat
     * expired and the client should re-issue the PING command. If a change
     * has been found, the client is sent a 2 status and should then issue a
     * SYNC command.
     *
     * @return boolean
     */
    public function handle(Horde_ActiveSync $activeSync, $devId)
    {
        $now = time();
        parent::handle($activeSync, $devId);

        /* Get the settings for the server */
        $ping_settings = $this->_driver->getHeartbeatConfig();
        $timeout = $ping_settings['waitinterval'];

        /* Notify */
        $this->_logger->info('[' . $devId . '] Ping received at timestamp: ' . $now . '.');

        /* Glass half full kinda guy... */
        $this->_statusCode = self::STATUS_NOCHANGES;

        /* Initialize the state machine */
        $state = &$this->_driver->getStateObject();

        /* See if we have an existing PING state. Need to do this here, before
         * we read in the PING request since the PING request is allowed to omit
         * sections if they have been sent previously */
        $collections = array_values($state->initPingState($this->_devId));
        $lifetime = $state->getHeartbeatInterval();
        if ($lifetime !== 0 && $lifetime < $ping_settings['heartbeatmin']) {
            $this->_statusCode = self::STATUS_HBOUTOFBOUNDS;
            $lifetime = $ping_settings['heartbeatmin'];
        } elseif ($lifetime > $ping_settings['heartbeatmax']) {
            $this->_statusCode = self::STATUS_HBOUTOFBOUNDS;
            $lifetime = $ping_settings['heartbeatmax'];
        }
        if ($this->_statusCode == self::STATUS_HBOUTOFBOUNDS) {
            $state->resetPingState();
            $this->_handleHBError($lifetime);
            $state->savePingState();
            return false;
        }

        /* Build the $collections array if we receive request from PIM */
        if ($this->_decoder->getElementStartTag(self::PING)) {
            if ($this->_decoder->getElementStartTag(self::HEARTBEATINTERVAL)) {
                $lifetime = $this->_decoder->getElementContent();
                if ($lifetime !== 0 && $lifetime < $ping_settings['heartbeatmin']) {
                    $this->_statusCode = self::STATUS_HBOUTOFBOUNDS;
                    $lifetime = $ping_settings['heartbeatmin'];
                } elseif ($lifetime > $ping_settings['heartbeatmax']) {
                    $this->_statusCode = self::STATUS_HBOUTOFBOUNDS;
                    $lifetime = $ping_settings['heartbeatmax'];
                }
                if ($this->_statusCode == self::STATUS_HBOUTOFBOUNDS) {
                    $state->resetPingState();
                    $this->_handleHBError($lifetime);
                    $state->savePingState();
                    return false;
                }
                $state->setHeartbeatInterval($lifetime);
                $this->_decoder->getElementEndTag();
            }

            if ($lifetime == 0) {
                $lifetime = $ping_settings['heartbeatdefault'];
            }

            if ($this->_decoder->getElementStartTag(self::FOLDERS)) {
                $collections = array();
                while ($this->_decoder->getElementStartTag(self::FOLDER)) {
                    $collection = array();
                    if ($this->_decoder->getElementStartTag(self::SERVERENTRYID)) {
                        $collection['id'] = $this->_decoder->getElementContent();
                        $this->_decoder->getElementEndTag();
                    }
                    if ($this->_decoder->getElementStartTag(self::FOLDERTYPE)) {
                        $collection['class'] = $this->_decoder->getElementContent();
                        $this->_decoder->getElementEndTag();
                    }

                    $this->_decoder->getElementEndTag();
                    array_push($collections, $collection);
                }

                if (!$this->_decoder->getElementEndTag()) {
                    $this->_statusCode = self::STATUS_PROTERROR;
                    return false;
                }
            }

            if (!$this->_decoder->getElementEndTag()) {
                $this->_statusCode = self::STATUS_PROTERROR;
                return false;
            }
        }

        $changes = array();
        $dataavailable = false;

        /* Start waiting for changes, but only if we don't have any errors */
        if ($this->_statusCode == self::STATUS_NOCHANGES) {
            $this->_logger->info(sprintf('[%s] Waiting for changes (heartbeat interval: %d)', $this->_devId, $lifetime));
            $expire = $now + $lifetime;
            while (time() <= $expire) {
                /* Check the remote wipe status and request a foldersync if
                 * we want the device wiped. */
                if ($this->_provisioning === true) {
                    $rwstatus = $state->getDeviceRWStatus($this->_devId);
                    if ($rwstatus == Horde_ActiveSync::PROVISION_RWSTATUS_PENDING || $rwstatus == Horde_ActiveSync::PROVISION_RWSTATUS_WIPED) {
                        $this->_statusCode = self::STATUS_FOLDERSYNCREQD;
                        break;
                    }
                }

                if (count($collections) == 0) {
                    $this->_logger->err('0 collections');
                    $this->_statusCode = self::STATUS_MISSING;
                    break;
                }

                for ($i = 0; $i < count($collections); $i++) {
                    $collection = $collections[$i];
                    $collection['synckey'] = $this->_devId;
                    $sync = $this->_driver->getSyncObject();
                    $state->loadPingCollectionState($collection);
                    try {
                        $sync->init($state, null, $collection);
                    } catch (Horde_ActiveSync_Exception $e) {
                        /* Stop ping if exporter cannot be configured */
                        $this->_logger->err('Ping error: Exporter can not be configured. Waiting 30 seconds before ping is retried.');
                        sleep(30);
                        break;
                    }

                    $changecount = $sync->GetChangeCount();
                    if ($changecount > 0) {
                        $dataavailable = true;
                        $changes[$collection['id']] = $changecount;
                        $this->_statusCode = self::STATUS_NEEDSYNC;
                    }

                    /* Update the state, but don't bother with the backend since we
                     * are not updating any data.*/
                    while (is_array($sync->syncronize(Horde_ActiveSync::BACKEND_DISCARD_DATA)));
                }

                if ($dataavailable) {
                    $this->_logger->info('[' . $this->_devId . '] Changes available');
                    break;
                }
                /* Wait a bit before trying again */
                sleep($timeout);
            }
        }

        /* Prepare for response */
        $this->_logger->info('[' . $this->_devId . '] Sending response for PING.');
        $this->_encoder->StartWBXML();

        $this->_encoder->startTag(self::PING);

        $this->_encoder->startTag(self::STATUS);
        $this->_encoder->content($this->_statusCode);
        $this->_encoder->endTag();

        $this->_encoder->startTag(self::FOLDERS);
        foreach ($collections as $collection) {
            if (isset($changes[$collection['id']])) {
                $this->_encoder->startTag(self::FOLDER);
                $this->_encoder->content($collection['id']);
                $this->_encoder->endTag();
            }
        }
        $this->_encoder->endTag();

        $this->_encoder->endTag();

        $state->savePingState();

        return true;
    }

    protected function _handleHBError($lifetime)
    {
        $this->_logger->info('[' . $this->_devId . '] Sending response for PING.');
        $this->_encoder->StartWBXML();
        $this->_encoder->startTag(self::PING);
        $this->_encoder->startTag(self::STATUS);
        $this->_encoder->content($this->_statusCode);
        $this->_encoder->endTag();
        $this->_encoder->startTag(self::HEARTBEATINTERVAL);
        $this->_encoder->content($lifetime);
        $this->_encoder->endTag();
        $this->_encoder->endTag();
    }
}
