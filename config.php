<?php
// Edit these values. Keep this file out of version control.
const DB_HOST = 'localhost';
const DB_NAME = 'bluehands';
const DB_USER = 'root';          // use a dedicated MySQL user in production
const DB_PASS = '';

// AI interview. Put your key here or set the ANTHROPIC_API_KEY environment variable.
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');
const ANTHROPIC_MODEL = 'claude-sonnet-5-5';

const FEE_PERCENT  = 3;          // taken from the worker's payout
const MAX_VIDEO_MB = 40;         // also raise upload_max_filesize and post_max_size in php.ini

ini_set('display_errors', '0');  // never show PHP errors to visitors