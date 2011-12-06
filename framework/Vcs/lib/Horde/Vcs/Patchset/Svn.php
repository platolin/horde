<?php
/**
 * Subversion patchset class.
 *
 * @author  Anil Madhavapeddy <anil@recoil.org>
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package Vcs
 */
class Horde_Vcs_Patchset_Svn extends Horde_Vcs_Patchset_Base
{
    /**
     * Constructor
     *
     * @param Horde_Vcs $rep  A Horde_Vcs repository object.
     * @param string $file    The filename to create patchsets for.
     */
    public function __construct($rep, $opts = array())
    {
        // TODO: Allow access via 'range'
        $fileOb = $rep->getFile($opts['file']);

        foreach ($fileOb->getLog() as $rev => $log) {
            $this->_patchsets[$rev] = array(
                'author' => $log->getAuthor(),
                'branch' => '',
                'date' => $log->getDate(),
                'log' => $log->getMessage(),
                'members' => array(),
                'tag' => ''
            );

            foreach ($log->getFiles() as $file) {
                $action = substr($file, 0, 1);
                $file = preg_replace('/.*?\s(.*?)(\s|$).*/', '\\1', $file);
                $to = $rev;
                $status = Horde_Vcs_Patchset::MODIFIED;
                if ($action == 'A') {
                    $from = null;
                    $status = Horde_Vcs_Patchset::ADDED;
                } elseif ($action == 'D') {
                    $from = $to;
                    $to = null;
                    $status = Horde_Vcs_Patchset::DELETED;
                } else {
                    // This technically isn't the previous revision,
                    // but it works for diffing purposes.
                    $from = $to - 1;
                }

                $this->_patchsets[$rev]['members'][] = array('file' => $file,
                                                             'from' => $from,
                                                             'to' => $to,
                                                             'status' => $status);
            }
        }

        return true;
    }
}
