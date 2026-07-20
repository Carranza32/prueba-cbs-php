<?php

namespace CBSNorthStar\Repositories;

class ProcessRepository
{
  protected $db;
  private static $instance = null;
  private function __construct()
  {
    global $wpdb;
    $this->db = $wpdb;

    return $this;
  }

  public static function create(): ?ProcessRepository
  {
    if (self::$instance === null) {
      self::$instance = new ProcessRepository();
    }

    return self::$instance;
  }

  public function getStatus(string $process)
  {

    $query = $this->db->prepare('SELECT * FROM cbs_processes where name = %s',[
      $process
    ]);

    $result = $this->db->get_results($query);

    return empty($result) ? [] : $result[0];
  }

  public function updateStatus(string $name, int $newValue, array $messages = null)
  {
    $newData = array(
      'result' => $newValue,
      'message' => json_encode($messages)
    );

    $where = array(
      'name' => $name
    );

    $format = array('%d','%s');


    $whereFormat = ['%s'];

    return $this->db->update('cbs_processes', $newData, $where, $format, $whereFormat);
  }
}
