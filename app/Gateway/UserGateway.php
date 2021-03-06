<?php

namespace App\Gateway;

use Illuminate\Database\ConnectionInterface;

class UserGateway
{
      /**
       * @var ConnectionInterface
       */
      private $db;

      public function __construct()
      {
            $this->db = app('db');
      }


      // Users
      public function getUser(string $userId)
      {
            $user = $this->db->table('users')
                  ->where('user_id', $userId)
                  ->first();

            if ($user) {
                  return (array) $user;
            }

            return null;
      }


      public function saveUser(string $userId, string $displayName)
      {
            $this->db->table('users')
                  ->insert([
                        'user_id' => $userId,
                        'display_name' => $displayName
                  ]);
      }

      function setUserProgress(string $userId, int $stepNumber)
      {
            $this->db->table('users')
                  ->update([
                        'number' => $stepNumber,
                        'user_id' => $userId
                  ]);
      }

      function setCurrency(string $userId, string $currency, string $column)
      {
            $this->db->table('users')
                  ->update([
                        $column => $currency,
                        'user_id' => $userId
                  ]);
      }
}
