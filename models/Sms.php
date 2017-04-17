<?php

namespace ihacklog\sms\models;

use Yii;
use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use common\models\User;
use ihacklog\sms\components\traits\ModuleTrait;

/**
 * This is the model class for table "sms".
 *
 * @property integer $id
 * @property integer $channel_type 通道类型//（1验证码通道，2 通知类短信通道）
 * @property integer $code_type 业务类型
 * @property integer $template_id 模板id
 * @property string $mobile 接收方手机号
 * @property string $content 短信内容
 * @property string $device_id 设备ID号//（WEB端发起的填写web）
 * @property string $verify_code 校验码内容
 * @property integer $verify_result 短信校验结果//（0,未校验，1成功，2失败）针对校验类短信
 * @property integer $send_status 发送状态//0未发送，1发送成功，2发送失败
 * @property string $error_msg 短信发送错误代码信息记录
 * @property integer $client_ip 客户端IPv4 地址
 * @property string $provider 服务商名称
 * @property integer $created_at
 * @property integer $updated_at
 */
class Sms extends ActiveRecord
{

    use ModuleTrait;
    //通道类型 1 验证类 ,  2 通知类
    const CHANNEL_TYPE_VERIFY = 1;
    const CHANNEL_TYPE_NOTICE = 2;

    //0注册会员, 1密码找回, 2修改密码, 3修改手机, 4发送新手机验证码, 5提现
    const CODE_TYPE_REG        = 0;
    const CODE_TYPE_FIND_PWD   = 1;
    const CODE_TYPE_MOD_PWD    = 2;
    const CODE_TYPE_MOD_MOBILE = 3;
    const CODE_TYPE_NEW_MOBILE = 4;
    const CODE_TYPE_DRAWAL     = 5;

    //校验状态
    const VERIFY_RESULT_INIT = 0;
    const VERIFY_RESULT_SUCC = 1;
    const VERIFY_RESULT_FAIL = 2;

    //发送状态
    const SEND_STATUS_INIT = 0;
    const SEND_STATUS_SUCC = 1;
    const SEND_STATUS_FAIL = 2;

    public $templateVars;

    public function init()
    {
        $this->channel_type = self::CHANNEL_TYPE_VERIFY;
        $this->device_id = 'web';
    }

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%sms}}';
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::className(),
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
                'value' => time(),
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['mobile'], 'required'],
            [['id', 'channel_type', 'code_type', 'template_id', 'verify_result', 'send_status', 'client_ip', 'created_at', 'updated_at'], 'integer'],
            [['mobile', 'provider'], 'string', 'max' => 20],
            [['content'], 'string', 'max' => 1024],
            [['device_id'], 'string', 'max' => 30],
            ['verify_code', 'validateVerifyCode'],
            [['error_msg'], 'string', 'max' => 128],
            ['template_id', 'safe'],
            ['templateVars', 'safe'],
        ];
    }

    /**
     * 确保验证码类短信的短信码内容不为空
     *
     * @param string $attribute Attribute name
     * @param array $params Params
     */
    public function validateVerifyCode($attribute, $params)
    {
        $hasError = false;

        if ($this->channel_type == self::CHANNEL_TYPE_VERIFY) {
            $hasError = empty($this->{$attribute}) ? true : false;
        } else {
            $hasError = true;
        }

        if ($hasError === true) {
            $this->addError($attribute, 'CHANNEL_TYPE_VERIFY need verify code not empty!');
        }
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'channel_type' => '通道类型',
            'code_type' => '业务类型',
            'template_id' => '短信模板id',
            'mobile' => '接收方手机号',
            'content' => '短信内容',
            'device_id' => '设备ID号',
            'verify_code' => '校验码内容',
            'verify_result' => '校验结果',
            'send_status' => '发送状态',
            'error_msg' => '错误信息',
            'client_ip' => '客户端IPv4地址',
            'provider' => '服务商名称',
            'created_at' => '创建时间 ',
            'updated_at' => '状态更新时间',
        ];
    }

    /**
     * @inheritdoc
     */
    public static function find()
    {
        return new SmsQuery(get_called_class());
    }

    /**
     * 添加一行短信发送任务
     *  @TODO 同一IP多次发送检测 ， 同一客户端多次重复请求检测， 黑名单检测
     * @param $mobile
     * @param $content
     * @param string $veriyCode
     * @param $errorMsg
     * @return mixed 主键id或false
     */
    public function send($mobile, $content, $veriyCode = '', $templateId, &$errorMsg)
    {
        $module = $this->getModule();
        //防恶意请求
        if ($module->resendTimeSpan) {
            if ($this->countByTime($mobile, $module->resendTimeSpan) > 0) {
                $errorMsg = sprintf('Send too frequently in %d s!', $module->resendTimeSpan);
                $this->addError('id', $errorMsg);
                return false;
            }
        }
        if ($module->singleIpTimeSpan) {
            if ($this->countByIp(sprintf("%u", ip2long(Yii::$app->getRequest()->getUserIP())), $module->singleIpTimeSpan)
                > $module->singleIpSendLimit) {
                $errorMsg = sprintf('Send too frequently in %d s for IP: %s!',
                    $module->singleIpTimeSpan,
                    Yii::$app->getRequest()->getUserIP());
                $this->addError('id', $errorMsg);
                return false;
            }
        }
        //插入发送记录到库中
        $smsId = $this->addTask($mobile, $content, $veriyCode, $templateId, 'web');
        if (!$smsId) {
            $errorMsg = 'failed to add sms task:';
            return false;
        }
        $sendRs = $this->doSend($mobile, $content, $templateId, $errorMsg);
        $updateRs = $this->updateSendStatus($sendRs, $errorMsg);
        if (true == $sendRs) {
            return true;
        }
        return false;
    }

    public function addTask($mobile, $content, $veriyCode = '', $templateId)
    {
        $id = self::makeOrderId();
        $this->id           = $id;
        $this->mobile = $mobile;
        $this->content = is_array($content) ? json_encode($content) : $content;
        $this->verify_code = $veriyCode;
        $this->template_id = $templateId;
        if ($this->save()) {
            return $id;
        } else {
            return false;
        }
    }

    public static function makeOrderId()
    {
        return date('YmdHis') . mt_rand(1000, 9999);
    }

    /**
     * 统计指定ip,在规定的时间内发送成功的短信条数(只计算发送成功的)
     * @param $ip
     * @param int $timeLimit
     * @return int|string
     */
    public function countByIp($ip, $timeLimit = 3600)
    {
        //当前时间与发送时间之间的间隔小于 $timeLimit
        $timeStart = time() - $timeLimit;
        $map = [
            'client_ip'   => $ip,
            'send_status' => self::SEND_STATUS_SUCC,
        ];
        return Sms::find()->where($map)->andWhere(['>', 'created_at', $timeStart])->count();
    }

    /**
     * 统计指定手机号，在规定的时间内请求发送短信数(不管成功与失败)
     *
     * @param mixed $mobile
     * @param int $timeSpan
     * @return int
     */
    public function countByTime($mobile, $timeSpan = 60)
    {
        $timeStart = time() - $timeSpan;
        return Sms::find()->where(['mobile'=> $mobile])->andWhere(['>', 'created_at', $timeStart])->count();
    }

    public function updateSendStatus($sendRs, $errorMsg)
    {
        $st = false == $sendRs ? self::SEND_STATUS_FAIL : self::SEND_STATUS_SUCC;
        //更新发送结果
        return $this->setSendStatus($st, $errorMsg);
    }

    /**
     * 更新发送状态
     *
     * @param mixed $st
     * @param string $msg
     * @return void
     */
    public function setSendStatus($st, $msg ='')
    {
        $this->send_status = $st;
        $this->error_msg = $msg;
        return $this->save(false);
    }

    /**
     * 更新验证状态
     *
     * @param mixed $st
     * @return void
     */
    public function setVerifyRs($st)
    {
        $this->verify_result = $st;
        $this->updated_at = time();
        return $this->save(false);
    }

    /**
     * 执行短信发送
     * @param $mobile
     * @param $content
     * @param $errorMsg
     * @return bool
     */
    public static function doSend($mobile, $content, $templateId, &$errorMsg)
    {
        $sms = Yii::$app->sms;
        $smsSendRs = $sms->setTemplateId($templateId)->send($mobile, $content);
        if (false == $smsSendRs) {
            $err_arr = $sms->getLastError();
            if (is_array($content)) {
                $content = json_encode($content);
            }
            $err_msg = 'sms_send_failed: sp:'. $sms->provider . ', to:'.  $mobile . ', content: '. $content .
                ', sp_error_msg: '. $err_arr['msg'];
            $errorMsg = $err_msg;
            Yii::getLogger()->log($err_msg, 'notice', 'sms');
            return false;
        } else {
            return true;
        }
    }

    /**
     * 发送短信(验证码之类的)
     * @param $mobile
     * @param string | array $verifyCode
     * @return mixed
     */
    public function sendVerify($mobile, $verifyCode = '', $templateId = null)
    {
        $errorMsg = '';
        if ('Yuntongxun' == Yii::$app->sms->provider) {
            $content = [$verifyCode, 5];
        } else {
            $content = '';
        }
        $rs = self::send($mobile, $content, $verifyCode, $templateId, $errorMsg);
        return $rs;
    }

    /**
     * 通知类短信发送
     * @param $mobile
     * @param string | array $content
     * @return mixed
     */
    public function sendNotice($mobile, $content = '', $templateId = null) {
        $errorMsg = '';
        $rs = self::send($mobile, $content, '', $templateId, $errorMsg);
        if (!$rs) {
            $this->addError('id', $errorMsg);
        }
        return $rs;
    }

    public function verify($mobile, $verifyCode, $templateId) {
        $map = array(
            'mobile'      => $mobile,
            'verify_code'  => $verifyCode,
            'template_id'    => $templateId,
            'channel_type' => self::CHANNEL_TYPE_VERIFY,
        );
        if (empty($verifyCode)) {
            return false;
        }
        //有效时间
        $timeout           = $this->getModule()->verifyTimeout ? $this->getModule()->verifyTimeout : 60*5;
        $startOffset       = time() - $timeout;
        $found  = Sms::find()->where($map)->andWhere(['>', 'created_at', $startOffset])->one();
        if ($found) {
            $id = $found->id;
            if ($found->verify_result == self::VERIFY_RESULT_SUCC) {
                $this->addError('id', '您的验证码已经被验证过！');
                return false;
            }
            $found->verify_result = self::VERIFY_RESULT_SUCC;
            $found->updated_at = time();
            return $found->save(false);
        } else {
            $this->addError('id', '没有找到匹配的验证码！');
            return false;
        }
    }

    /**
     * @inheritdoc
     * id不自动生成
     */
    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {
            if ($this->isNewRecord) {
                $this->id = date('YmdHis') . mt_rand(1000,9999);
                $this->client_ip = sprintf('%u', ip2long(Yii::$app->request->userIP));
                $this->created_at = time();
                $this->verify_result = self::VERIFY_RESULT_INIT;
                $extraArr = [];
                if ($this->channel_type == self::CHANNEL_TYPE_VERIFY && empty($this->verify_code)) {
                    $this->verify_code = self::generateCode();
                    $extraArr['{verify_code}'] = $this->verify_code;
                }
                //模块替换
/*                if ($this->template_id) {
                    $template = SmsTemplate::findOne($this->template_id);
                    $template->parse($extraArr);
                    $user= User::findOne(['mobile' => $this->mobile]);
                    if ($user) {
                        $varArr = [
                            '{username}' => $user->username,
                            '{email}' => $user->email,
                            '{mobile}' => $user->mobile,
                        ];
                    }
                    $content = $template->parse($varArr)->getParsedContent();
                    $this->content = $content;
                } else {
                    $this->content = '您的验证码为: '. $this->verify_code;
                }*/
                $this->content = '您的验证码为: '. $this->verify_code;
                $this->provider = Yii::$app->sms->provider;
            }
            return true;
        } else {
            return false;
        }
    }

    public static function generateCode()
    {
        return mt_rand(1000,9999);
    }

    public static function getChannelTypeArr()
    {
        return [
            self::CHANNEL_TYPE_VERIFY => '验证码通道',
            self::CHANNEL_TYPE_NOTICE => '通知类通道',
        ];
    }
}
