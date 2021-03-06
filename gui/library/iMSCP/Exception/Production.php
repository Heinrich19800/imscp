<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2019 by Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

/** @noinspection PhpUnhandledExceptionInspection PhpDocMissingThrowsInspection */

/**
 * Class iMSCP_Exception_Production
 */
class iMSCP_Exception_Production extends iMSCP_Exception
{
    /**
     * @inheritdoc
     */
    public function __construct($message = '', $code = 0, \Throwable $previous = NULL)
    {
        if (function_exists('tr')) {
            $message = tr('An unexpected error occurred. Please contact your administrator.');
        } else {
            $message = 'An unexpected error occurred. Please contact your administrator.';
        }

        parent::__construct($message, $code, $previous);
    }
}
