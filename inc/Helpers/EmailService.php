<?php
declare(strict_types=1);

namespace CBSNorthStar\Helpers;

class EmailService
{
   public function __construct(
     protected string $to,
     protected string $subject,
     protected string $view,
     protected array $data
   ) {}

  public static function send($to,$subject,$view, $data): void
  {
    $email = new static($to,$subject,$view, $data);
    $email->sendMail();
  }

  public function sendMail(): void
  {
        if (is_array($this->data)) {
            extract($this->data);
        }


    ob_start();
    include_once(__DIR__ . '/../../template/emails/' . $this->view . '.php');

    $html_content = ob_get_clean();
    $headers = array('Content-Type: text/html; charset=UTF-8');

    wp_mail($this->to, $this->subject, $html_content, $headers);
  }
}
