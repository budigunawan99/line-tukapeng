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
                        // if (!isset($event['source']['userId'])) continue;

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
                  }
            }


            $this->response->setContent("No events found!");
            $this->response->setStatusCode(200);
            return $this->response;
      }

      private function followCallback($event)
      {

            if (isset($event['source']['userId'])) {
                  $res = $this->bot->getProfile($event['source']['userId']);
                  if ($res->isSucceeded()) {
                        $profile = $res->getJSONDecodedBody();

                        // create welcome message
                        $message  = "Selamat datang, " . $profile['displayName'] . "!\n";
                        $message .= "Kami akan membantumu menghitung nilai mata uang asing ke dalam rupiah.\nSilahkan ketik \"tukapeng\" untuk mulai!";
                        $textMessageBuilder = new TextMessageBuilder($message);

                        // create sticker message
                        $stickerMessageBuilder = new StickerMessageBuilder(1, 3);

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
            } elseif (isset($event['source']['groupId'])) {
                  $res = $this->bot->getProfile($event['source']['userId']);
                  if ($res->isSucceeded()) {
                        $profile = $res->getJSONDecodedBody();

                        // create welcome message
                        $message  = "Hai, " . $profile['displayName'] . "!\n";
                        $message .= "Tambahkan aku menjadi temanmu untuk bisa menggunakan fitur chatbot!";
                        $textMessageBuilder = new TextMessageBuilder($message);
                        // send reply message
                        $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
                  }
            }
      }
}
