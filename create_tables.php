<?php

/**
 * Create tables on plugin activation
 */

global $wpdb;

$table_name_images = $wpdb->prefix . "picture_record";
$woocommerce_car_record = $wpdb->prefix . "woocommerce_cart_record";


$collate = $wpdb->has_cap('collation') ? $wpdb->get_charset_collate() : '';

require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

$queries = [];

// Session event tracking table — one row per order lifecycle event per browser session
$queries[] = "
CREATE TABLE {$wpdb->prefix}cbs_session_events (
    id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_ref varchar(20)         NOT NULL,
    event_type      varchar(50)         NOT NULL,
    wc_order_id     bigint(20) UNSIGNED DEFAULT NULL,
    site_id         varchar(100)        DEFAULT NULL,
    status          varchar(20)         NOT NULL,
    details         longtext            DEFAULT NULL,
    created_at      datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_transaction_ref (transaction_ref),
    KEY idx_wc_order_id     (wc_order_id),
    KEY idx_status          (status),
    KEY idx_created_at      (created_at)
) {$collate}";

// AI-powered log insights table (used by CBSLogger + AIErrorAnalyzer)
$queries[] = "
CREATE TABLE {$wpdb->prefix}cbs_ai_insights (
    id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    error_hash  varchar(64)         NOT NULL,
    channel     varchar(50)         NOT NULL,
    level       varchar(20)         NOT NULL,
    original_message text           NOT NULL,
    ai_explanation   text,
    ai_suggestion    text,
    context_data     longtext,
    ai_status   varchar(30)         NOT NULL DEFAULT 'pending',
    analyzed_at datetime            DEFAULT NULL,
    created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_error_hash (error_hash),
    KEY idx_channel_level (channel, level),
    KEY idx_created_at (created_at)
) {$collate}";

$queries[] = "
CREATE TABLE cbs_site_details (
    id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
    siteid varchar(255) NOT NULL,
    site_name varchar(255),
    menu_type varchar(255),
    areaid varchar(255),
    area_name varchar(255),
    address1 varchar(255),
    address2 varchar(255),
    state varchar(255),
    city varchar(255),
    zipcode varchar(255),
    countrycode varchar(255),
    phone varchar(255),
    latitude varchar(255),
    longitude varchar(255),
    startofbusinesstime varchar(255),
    startofbusinessweek varchar(255),
    kitchenopentime varchar(255),
    kitchenclosetime varchar(255),
    payperiodstartdate varchar(255),
    payperiodstartime varchar(255),
    isactive varchar(255),
    timezone varchar(255),
    enableseatNumber varchar(255),
    config_id int(11),
    pay_later_control varchar(100) DEFAULT 'Disabled',
    shipping_control varchar(100) DEFAULT 'Disabled',
    payment_control varchar(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY id (id)
    ) {$collate}";

$queries[] = "CREATE TABLE cbs_configure_details (
     id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
                token varchar(255) NOT NULL,
                instance varchar(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY id (id)
    ) {$collate}";

$queries[] = "CREATE TABLE cbs_instances (
    id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
    instance_name varchar(255) NOT NULL,
    instance_ecmurl varchar(255),
    instance_oeapiurl varchar(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY id (id),
    UNIQUE KEY instance_name (instance_name)
) {$collate}";

$queries[] = "CREATE TABLE cbs_save_api_response (
    id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
    siteid varchar(255) NOT NULL,
    sites_rules longtext,
    servingoptions_rules longtext,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY id (id)
) {$collate}";

$queries[] = "CREATE TABLE wp_woocommerce_cart_record (
    id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
    location_number int(11) ,
    area_id text ,
    cart_data text,
    check_id varchar(100) DEFAULT NULL,
    finalized tinyint(4) DEFAULT '0',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY id (id)
) {$collate}";

$queries[] = "CREATE TABLE $table_name_images (
    id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
    itemid varchar(255) NOT NULL,
    mediaitemid text NOT NULL,
    pictureid text,
    expected_bytes bigint(20) unsigned DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY id (id)
) {$collate}";

$queries[] = "CREATE TABLE cbs_webhook_registration (
    id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
    siteid varchar(255) NOT NULL,
    tokenid varchar(255) NOT NULL,
    webhookid varchar(255),
    secret varchar(255),
    webhookurl varchar(255),
    webhooktype varchar(255),
    callbackdata longtext,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY id (id)
) {$collate}";
// NOTE: the UNIQUE KEY on (siteid, menuid, daypartid) is added by
// cbs_migrate_daypartmenus_unique_key() in migrations.php (it uses column
// prefixes because siteid is LONGTEXT, which dbDelta cannot express reliably).
$queries[] = "CREATE TABLE cbs_daypartmenus (
  id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
  menuid varchar(255) NOT NULL,
  daypartid varchar(255) NOT NULL,
  starttime time  NOT NULL,
  endtime time  NOT NULL,
  days varchar(255)  NOT NULL,
  displayorder int(11)  NOT NULL,
  siteid longtext  NOT NULL,
  UNIQUE KEY id (id)
) {$collate}";

$queries[] = "CREATE TABLE cbs_time_zone_settings (
   id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
  timezone_name varchar(255) NOT NULL,
  timezone_desc varchar(255),
  gmt_offset varchar(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY id (id),
  UNIQUE KEY timezone_name (timezone_name)
) {$collate}";

$queries[] = "CREATE TABLE cbs_api_calls_log (
  id int(11) NOT NULL AUTO_INCREMENT,
  action varchar(45) DEFAULT NULL,
  PRIMARY KEY id (id)
)  {$collate}";

$queries[] = "CREATE TABLE wp_woocommerce_cart_record (
  id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
  location_number int(11) ,
  area_id text ,
  cart_data text,
  check_id varchar(100) DEFAULT NULL,
  finalized tinyint(4) DEFAULT '0',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
  ){$collate}";

$queries[] = "CREATE TABLE cbs_processes (
  id int not null auto_increment,
  name text not null,
  result int not null comment '0 = all good, 1 = running, 2 = Errors',
  message text null,
  PRIMARY KEY (`id`)
) {$collate}
";

// Product deploy run log — one row per deploy attempt (manual or hook-triggered).
// Powers the "Product Report Deploy" admin page.
$queries[] = "
CREATE TABLE {$wpdb->prefix}cbs_product_run_log (
    id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    run_id             VARCHAR(36)      NOT NULL,
    trigger_type       VARCHAR(20)      NOT NULL,
    trigger_source     VARCHAR(100)     DEFAULT NULL,
    user_id            BIGINT UNSIGNED  DEFAULT NULL,
    user_login         VARCHAR(60)      DEFAULT NULL,
    status             VARCHAR(20)      NOT NULL DEFAULT 'running',
    products_attempted INT UNSIGNED     NOT NULL DEFAULT 0,
    products_succeeded INT UNSIGNED     NOT NULL DEFAULT 0,
    products_failed    INT UNSIGNED     NOT NULL DEFAULT 0,
    products_skipped   INT UNSIGNED     NOT NULL DEFAULT 0,
    started_at         DATETIME         DEFAULT NULL,
    finished_at        DATETIME         DEFAULT NULL,
    last_heartbeat_at  DATETIME         DEFAULT NULL,
    duration_ms        INT UNSIGNED     DEFAULT NULL,
    error_message      TEXT             DEFAULT NULL,
    error_code         VARCHAR(50)      DEFAULT NULL,
    blocked_by_run_id   VARCHAR(36)      DEFAULT NULL,
    conflict_type       VARCHAR(80)      DEFAULT NULL,
    retried_from_run_id VARCHAR(36)      DEFAULT NULL,
    plugin_version      VARCHAR(20)      NOT NULL DEFAULT '',
    created_at         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY run_id (run_id),
    KEY status_created_at (status, created_at),
    KEY trigger_type_status (trigger_type, status),
    KEY created_at (created_at)
) {$collate}";

// Product deploy run events — append-only audit trail, one row per event within a run.
$queries[] = "
CREATE TABLE {$wpdb->prefix}cbs_product_run_events (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    run_id      VARCHAR(36)      NOT NULL,
    event_type  VARCHAR(80)      NOT NULL,
    severity    VARCHAR(10)      NOT NULL,
    message     TEXT             NOT NULL,
    product_id  BIGINT UNSIGNED  DEFAULT NULL,
    product_sku VARCHAR(100)     DEFAULT NULL,
    occurred_at DATETIME         NOT NULL,
    context     LONGTEXT         DEFAULT NULL,
    PRIMARY KEY  (id),
    KEY run_id_occurred_at (run_id, occurred_at),
    KEY event_type (event_type),
    KEY severity_occurred_at (severity, occurred_at),
    KEY product_id (product_id)
) {$collate}";

foreach ($queries as $sql) {
    dbDelta( $sql );
}

$Bethpage=array('Bethpage','https://ecm-bethpage.cbsnorthstar.com','https://oeapi3.cbsnorthstar.com');
$Callaway=array('Callaway','https://ecm-callaway.cbsnorthstar.com','https://oeapi-callaway.cbsnorthstar.com');
$Cypress=array('Cypress','https://ecm-cypress.cbsnorthstar.com','https://oeapi-cypress.cbsnorthstar.com');
$Dornoch=array('Dornoch','https://ecm-dornoch.cbsnorthstar.com','https://oeapi-dornoch.cbsnorthstar.com');
$Eastlake=array('Eastlake','https://ecm-eastlake.cbsnorthstar.com','https://oeapi-eastlake.cbsnorthstar.com');
$Firestone=array('Firestone','https://ecm-firestone.cbsnorthstar.com','https://oeapi-firestone.cbsnorthstar.com');
$Ping=array('Ping','https://ecm-ping.cbsnorthstar.com','https://oeapi-ping.cbsnorthstar.com');
$YLCC=array('YLCC','https://ecm-ylcc.cbsnorthstar.com','https://oeapi-ylcc.cbsnorthstar.com');
$AWS=array('AWS','https://ecm2.cbsnorthstar.com','https://oeapi-aws.cbsnorthstar.com');

$instance_record=array($Bethpage,$Callaway,$Cypress,$Dornoch,$Eastlake,$Firestone,$Ping,$YLCC,$AWS);
foreach ($instance_record as $inst_details)
{

  $wpdb->query(
    $wpdb->prepare("
        INSERT IGNORE INTO cbs_instances(instance_name,instance_ecmurl,instance_oeapiurl)
        values('".$inst_details[0]."','".$inst_details[1]."','".$inst_details[2]."')"
    )
  );

}
$date = new DateTime();
$now = $date->format("Y-m-d H:i:s");

$cbs_time_zone_settings = array(
  array('id' => '1','timezone_name' => 'GMT',
    'timezone_desc' => 'Greenwich Mean Time',
    'gmt_offset' => '0',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '2','timezone_name' => 'UTC',
    'timezone_desc' => 'Universal Coordinated Time',
    'gmt_offset' => '0',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '3','timezone_name' => 'ECT',
    'timezone_desc' => 'European Central Time',
    'gmt_offset' => '+1:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '4','timezone_name' => 'EET',
    'timezone_desc' => 'Eastern European Time',
    'gmt_offset' => '+2:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '5','timezone_name' => 'ART',
    'timezone_desc' => '(Arabic) Egypt Standard Time',
    'gmt_offset' => '+2:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '6','timezone_name' => 'EAT',
    'timezone_desc' => 'Eastern African Time',
    'gmt_offset' => '+3:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '7','timezone_name' => 'MET',
    'timezone_desc' => 'Middle East Time',
    'gmt_offset' => '+3:30',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '8','timezone_name' => 'NET',
    'timezone_desc' => 'Near East Time',
    'gmt_offset' => '+4:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '9','timezone_name' => 'PLT',
    'timezone_desc' => 'Pakistan Lahore Time',
    'gmt_offset' => '+5:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '10','timezone_name' => 'IST',
    'timezone_desc' => 'India Standard Time',
    'gmt_offset' => '+5:30',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '11','timezone_name' => 'BST',
    'timezone_desc' => 'Bangladesh Standard Time',
    'gmt_offset' => '+6:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '12','timezone_name' => 'VST',
    'timezone_desc' => 'Vietnam Standard Time',
    'gmt_offset' => '+7:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '13','timezone_name' => 'CTT',
    'timezone_desc' => 'China Taiwan Time',
    'gmt_offset' => '+8:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '14','timezone_name' => 'JST',
    'timezone_desc' => 'Japan Standard Time   ',
    'gmt_offset' => '+9:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '15','timezone_name' => 'ACT',
    'timezone_desc' => 'Australia Central Time    ',
    'gmt_offset' => '+9:30',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '16','timezone_name' => 'AET',
    'timezone_desc' => 'Australia Eastern Time    ',
    'gmt_offset' => '+10:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '17','timezone_name' => 'SST',
    'timezone_desc' => 'Solomon Standard Time ',
    'gmt_offset' => '+11:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '18','timezone_name' => 'NST',
    'timezone_desc' => 'New Zealand Standard Time ',
    'gmt_offset' => '+12:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '19','timezone_name' => 'MIT',
    'timezone_desc' => 'Midway Islands Time   ',
    'gmt_offset' => '-11:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '20','timezone_name' => 'HST',
    'timezone_desc' => 'Hawaii Standard Time',
    'gmt_offset' => '-10:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '21','timezone_name' => 'AST',
    'timezone_desc' => 'Alaska Standard Time',
    'gmt_offset' => '-09:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '22','timezone_name' => 'PST',
    'timezone_desc' => 'Pacific Standard Time',
    'gmt_offset' => '-08:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '23','timezone_name' => 'PNT',
    'timezone_desc' => 'Phoenix Standard Time',
    'gmt_offset' => '-07:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '24','timezone_name' => 'MST',
    'timezone_desc' => 'Mountain Standard Time    ',
    'gmt_offset' => '-07:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '25','timezone_name' => 'CST',
    'timezone_desc' => 'Central Standard Time',
    'gmt_offset' => '-06:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '26','timezone_name' => 'EST',
    'timezone_desc' => 'Eastern Standard Time',
    'gmt_offset' => '-5:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '27','timezone_name' => 'IET',
    'timezone_desc' => 'Indiana Eastern Standard Time',
    'gmt_offset' => '-5:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '28','timezone_name' => 'PRT',
    'timezone_desc' => 'Puerto Rico and US Virgin Islands Time',
    'gmt_offset' => '-4:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '29','timezone_name' => 'CNT',
    'timezone_desc' => 'Canada Newfoundland Time',
    'gmt_offset' => '-3:30',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '30','timezone_name' => 'AGT',
    'timezone_desc' => 'Argentina Standard Time',
    'gmt_offset' => '-3:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '31','timezone_name' => 'BET',
    'timezone_desc' => 'Brazil Eastern Time',
    'gmt_offset' => '-3:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '32','timezone_name' => 'CAT',
    'timezone_desc' => 'Central African Time',
    'gmt_offset' => '-1:00',
    'created_at' => $now,
    'updated_at' => $now),
  // Added for OE-26686 (product-active-date-window): SiteClock::epochForSiteLocal()
  // looks up a site's timezone_desc here to convert ECM's site-local Start/Stop
  // wall-clock into a real UTC epoch. Sites configured with either BCL/Windows
  // timezone name had no matching row, so the lookup silently returned null and
  // writeActiveDateMeta() deleted the active-date meta instead of storing it.
  array('id' => '33','timezone_name' => 'CAST',
    'timezone_desc' => 'Central America Standard Time',
    'gmt_offset' => '-6:00',
    'created_at' => $now,
    'updated_at' => $now),
  array('id' => '34','timezone_name' => 'MOST',
    'timezone_desc' => 'Morocco Standard Time',
    'gmt_offset' => '+1:00',
    'created_at' => $now,
    'updated_at' => $now)
);

foreach ($cbs_time_zone_settings as $cbs_time_zones)
{
    foreach ($cbs_time_zones as $value) {
      $timezone_name=$cbs_time_zones['timezone_name'];
      $timezone_desc=$cbs_time_zones['timezone_desc'];
      $gmt_offset=$cbs_time_zones['gmt_offset'];
    }

  $wpdb->query(
    $wpdb->prepare("
        INSERT IGNORE INTO
            cbs_time_zone_settings(timezone_name,timezone_desc,gmt_offset)
        values('".$timezone_name."','".$timezone_desc."','".$gmt_offset."')
    ")
  );

}

$wpdb->query(
  $wpdb->prepare("
        INSERT IGNORE INTO
            cbs_processes(name,result)
        values('save_product', 0)
    ")
);
