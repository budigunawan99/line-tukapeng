<?php

namespace App\Http\Controllers;

use App\Gateway\EventLogGateway;
use App\Gateway\UserGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Logger;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use LINE\LINEBot\MessageBuilder\RawMessageBuilder;
use LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;

class Webhook extends Controller
{
      /**
       * @var LINEBot
       */
      private $bot;
      /**
       * @var Request
       */
      private $request;
      /**
       * @var Response
       */
      private $response;
      /**
       * @var Logger
       */
      private $logger;
      /**
       * @var EventLogGateway
       */
      private $logGateway;
      /**
       * @var UserGateway
       */
      private $userGateway;
      /**
       * @var array
       */
      private $user;


      public function __construct(
            Request $request,
            Response $response,
            Logger $logger,
            EventLogGateway $logGateway,
            UserGateway $userGateway
      ) {
            $this->request = $request;
            $this->response = $response;
            $this->logger = $logger;
            $this->logGateway = $logGateway;
            $this->userGateway = $userGateway;

            // create bot object
            $httpClient = new CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
            $this->bot  = new LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);
      }

      public function __invoke()
      {
            // get request
            $body = $this->request->all();

            // debuging data
            $this->logger->debug('Body', $body);

            // save log
            $signature = $this->request->server('HTTP_X_LINE_SIGNATURE') ?: '-';
            $this->logGateway->saveLog($signature, json_encode($body, true));

            return $this->handleEvents();
      }

      private function handleEvents()
      {
            $data = $this->request->all();

            if (is_array($data['events'])) {
                  foreach ($data['events'] as $event) {
                        // skip group and room event
                        if (!isset($event['source']['userId'])) continue;

                        if (isset($event['source']['userId'])) {
                              // get user data from database
                              $this->user = $this->userGateway->getUser($event['source']['userId']);

                              // if user not registered
                              if (!$this->user) $this->followCallback($event);
                              else {
                                    // respond event
                                    if ($event['type'] == 'message') {
                                          if (method_exists($this, $event['message']['type'] . 'Message')) {
                                                $this->{$event['message']['type'] . 'Message'}($event);
                                          }
                                    } else {
                                          if (method_exists($this, $event['type'] . 'Callback')) {
                                                $this->{$event['type'] . 'Callback'}($event);
                                          }
                                    }
                              }
                        } elseif (isset($event['source']['groupId']) && $event['type'] === 'join') {
                              if (method_exists($this, $event['type'] . 'Callback')) {
                                    $this->{$event['type'] . 'Callback'}($event);
                              }
                        } else {
                              continue;
                        }
                  }
            }


            $this->response->setContent("No events found!");
            $this->response->setStatusCode(200);
            return $this->response;
      }

      private function followCallback($event)
      {
            $res = $this->bot->getProfile($event['source']['userId']);
            if ($res->isSucceeded()) {
                  $profile = $res->getJSONDecodedBody();

                  // create welcome message
                  $message  = "Selamat datang, " . $profile['displayName'] . "!\n";
                  $message .= "Kami akan membantumu menghitung kurs mata uang asing.\nSilahkan kirim pesan \"tukapeng\" untuk memulai.";
                  $textMessageBuilder = new TextMessageBuilder($message);

                  // create sticker message
                  $stickerMessageBuilder = new StickerMessageBuilder(11537, 52002759);

                  // merge all message
                  $multiMessageBuilder = new MultiMessageBuilder();
                  $multiMessageBuilder->add($textMessageBuilder);
                  $multiMessageBuilder->add($stickerMessageBuilder);

                  // send reply message
                  $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);

                  // save user data
                  $this->userGateway->saveUser(
                        $profile['userId'],
                        $profile['displayName']
                  );
            }
      }

      private function joinCallback($event)
      {
            $res = $this->callAPI('https://api.line.me/v2/bot/group/' . $event['source']['groupId'] . '/summary');
            $this->logger->debug('group', $res);
            // create welcome message
            $message  = "Terima Kasih telah mengundang saya ke grup " . $res['groupName'] . "!\n";
            $message .= "Saya akan membantumu menghitung kurs mata uang asing.\nSilahkan kirim pesan \"tukapeng\" untuk memulai.";
            $textMessageBuilder = new TextMessageBuilder($message);

            // create sticker message
            $stickerMessageBuilder = new StickerMessageBuilder(11537, 52002759);

            // merge all message
            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder);
            $multiMessageBuilder->add($stickerMessageBuilder);

            // send reply message
            $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
      }

      private function textMessage($event)
      {
            $userMessage = $event['message']['text'];
            if ($this->user['number'] == 0) {
                  if (strtolower($userMessage) == 'tukapeng') {
                        $this->userGateway->setCurrency($this->user['user_id'], 'IDR', 'currency');
                        $this->userGateway->setCurrency($this->user['user_id'], 'IDR', 'currencyto');
                        $this->userGateway->setUserProgress($this->user['user_id'], 1);
                        $this->sendListCurrency($event['replyToken'], 'currency_options.json');
                  } else {
                        $message = 'Silakan kirim pesan "tukapeng" untuk memulai.';
                        $textMessageBuilder = new TextMessageBuilder($message);
                        $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
                  }
            } elseif ($this->user['number'] == 1) {
                  if (in_array($userMessage, $this->listedCurrency())) {
                        $this->userGateway->setCurrency($this->user['user_id'], $userMessage, 'currency');
                        $this->userGateway->setUserProgress($this->user['user_id'], $this->user['number'] + 1);
                        $this->sendListCurrency($event['replyToken'], 'currencyTo_options.json');
                  } else {
                        $message = 'Mohon pilih mata uang yang tersedia!';
                        $textMessageBuilder = new TextMessageBuilder($message);
                        $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
                  }
            } elseif ($this->user['number'] == 2) {
                  $this->inputMoney($userMessage, $event['replyToken']);
            } elseif ($this->user['number'] == 3) {
                  $this->showConversion($userMessage, $event['replyToken']);
            }
      }

      private function stickerMessage($event)
      {
            // create sticker message
            $stickerMessageBuilder = new StickerMessageBuilder(11537, 52002759);

            // create text message
            $message = 'Silakan kirim pesan "tukapeng" untuk memulai.';
            $textMessageBuilder = new TextMessageBuilder($message);

            // merge all message
            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder);
            $multiMessageBuilder->add($stickerMessageBuilder);

            // send message
            $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
      }

      private function sendListCurrency($replyToken, $viewpath)
      {
            $path = url($viewpath);
            $flexTemplate = file_get_contents($path);
            $message = new RawMessageBuilder([
                  'type'     => 'flex',
                  'altText'  => 'Currency Options',
                  'contents' => json_decode($flexTemplate)
            ]);
            $this->bot->replyMessage($replyToken, $message);
      }

      private function listedCurrency()
      {
            return array(
                  "USD", "EUR", "SGD", "JPY", "IDR", "HKD"
            );
      }

      private function inputMoney($userMessage, $replyToken)
      {


            if (in_array($userMessage, $this->listedCurrency())) {
                  // update number progress
                  $this->userGateway->setCurrency($this->user['user_id'], $userMessage, 'currencyto');
                  $this->userGateway->setUserProgress($this->user['user_id'], $this->user['number'] + 1);
                  $message = 'Silahkan input jumlah uang yang ingin dikonversikan.';
                  $textMessageBuilder = new TextMessageBuilder($message);
                  $this->bot->replyMessage($replyToken, $textMessageBuilder);
            } else {
                  $message = 'Mohon pilih mata uang yang tersedia!';
                  $textMessageBuilder = new TextMessageBuilder($message);
                  $this->bot->replyMessage($replyToken, $textMessageBuilder);
            }
      }

      private function showConversion($userMessage, $replyToken)
      {
            if (is_numeric($userMessage)) {
                  $baseCurrency = $this->user['currency'];
                  $toCurrency = $this->user['currencyto'];
                  $url = 'https://api.exchangeratesapi.io/latest?base=' . $baseCurrency;
                  $exchangerate = $this->callAPI($url);

                  $result = $this->conversion($exchangerate['rates'][$toCurrency], $userMessage);
                  $this->logger->debug('api', $exchangerate);
                  $message = "Hasil konversi dari " . number_format($userMessage, 2) . " " . $baseCurrency . " ke " . $toCurrency . " adalah " . number_format($result, 2) . " " . $toCurrency . ".\n\nTerima Kasih telah menggunakan layanan kami!";
                  $textMessageBuilder = new TextMessageBuilder($message);
                  $stickerMessageBuilder = new StickerMessageBuilder(11538, 51626502);

                  $multiMessageBuilder = new MultiMessageBuilder();
                  $multiMessageBuilder->add($textMessageBuilder);
                  $multiMessageBuilder->add($stickerMessageBuilder);

                  $this->bot->replyMessage($replyToken, $multiMessageBuilder);
                  $this->userGateway->setUserProgress($this->user['user_id'], 0);
            } else {
                  $message = 'Mohon masukkan jumlah uang yang ingin dikonversikan dengan benar!';
                  $textMessageBuilder = new TextMessageBuilder($message);
                  $this->bot->replyMessage($replyToken, $textMessageBuilder);
            }
      }

      private function conversion($exchangerate, $total)
      {
            return $exchangerate * $total;
      }

      private function callAPI($url)
      {
            $curl = curl_init();

            curl_setopt_array($curl, array(
                  CURLOPT_URL => $url,
                  CURLOPT_RETURNTRANSFER => true,
                  CURLOPT_ENCODING => '',
                  CURLOPT_MAXREDIRS => 10,
                  CURLOPT_TIMEOUT => 0,
                  CURLOPT_FOLLOWLOCATION => true,
                  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                  CURLOPT_CUSTOMREQUEST => 'GET',
            ));

            $response = curl_exec($curl);
            if (!$response) {
                  die("Connection Failure");
            }
            curl_close($curl);

            return json_decode($response, true);
      }
}
