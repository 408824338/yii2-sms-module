# yii2-sms-module
xyz.  

module config:

```php
    'modules' => [
        'sms' => [
            'class' => 'ihacklog\sms\Module',
            'userModelClass' => '\common\models\User', // optional. your User model. Needs to be ActiveRecord.
            'resendTimeSpan' => 10, //重发时间间隔(单位：秒）
            'singleIpTimeSpan' => 0, //单个ip用于统计允许发送的最多次数的限定时间
            'singleIpSendLimit' => 0, //单个ip在限定的时间内允许发送的最多次数
            'verifyTimeout' => 300, //验证码超时(秒)
        ],
```

test

        $sms = new \ihacklog\sms\models\Sms();
        $code = mt_rand(1000, 9999);
        var_dump($sms->sendVerify('18812345678', $code, 1));
        var_dump($sms->getErrors());
        var_dump($sms->verify('18812345678', $code, 1));
        die();


# yii2-sms component

* author: HuangYeWuDeng
* website: http://80x86.io


# installation

edit composer.json, and then add:
```json
 "repositories": [
   {
     "type": "git",
     "url": "https://github.com/ihacklog/yii2-sms.git"
   }
  ],
```

```json
  "require": {
    "ihacklog/yii2-sms": "^1.0"
  },
```

# config

```php
    'components' => [
        'sms' => [
            'class' => 'ihacklog\sms\Sms',
            'provider' => 'Yuntongxun', //set default provider
            'services' => [
                //see http://www.yuntongxun.com/member/main
                'Yuntongxun' => [
                    'class' => 'ihacklog\sms\provider\Yuntongxun',
                    'apiUrl' => 'https://app.cloopen.com:8883',
                    //                  'apiUrl' => 'https://sandboxapp.cloopen.com:8883',
                    'templateId' => 1,
                    'appId' => 'AppID',
                    'accountSid' => 'ACCOUNT SID',
                    'accountToken' => 'AUTH TOKEN',
                    'softVersion' => '2013-12-26',
                ],
                'File' => [
                    'class' => 'ihacklog\sms\provider\File',
                ],
            ],
        ],
    ],
```

#usage

simple usage:

        Yii::$app->sms->send('18899998888', ['6532','5']);

switch provider and set template id:
```php
    Yii::$app->sms
    ->setProvider('File')
    ->setTemplateId(3)
    ->send('18899998888', ['6532','5']);
```
