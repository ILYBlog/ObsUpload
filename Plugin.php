<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 华为云OBS上传插件
 *
 * @package ObsUpload
 * @author 猫咪修改版
 * @version 1.1.0
 * @link https://biux.cn/
 */

require 'OBS_SDK/vendor/autoload.php';
require 'OBS_SDK/obs-autoloader.php';

use Obs\ObsClient;

class ObsUpload_Plugin implements Typecho_Plugin_Interface
{
    //上传文件目录
    const UPLOAD_DIR = 'usr/uploads';

    /* 激活插件方法 */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array('ObsUpload_Plugin', 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array('ObsUpload_Plugin', 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array('ObsUpload_Plugin', 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array('ObsUpload_Plugin', 'attachmentHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentDataHandle = array('ObsUpload_Plugin', 'attachmentDataHandle');
    }

    /* 禁用插件方法 */
    public static function deactivate()
    {
    }

    /* 插件配置方法 */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $ak = new Typecho_Widget_Helper_Form_Element_Text('ak', NULL, '', _t('Access Key ID'));
        $form->addInput($ak->addRule('required', _t('Access Key ID不能为空！')));

        $sk = new Typecho_Widget_Helper_Form_Element_Text('sk', NULL, '', _t('Secret Access Key'));
        $form->addInput($sk->addRule('required', _t('Secret Access Key不能为空！')));

        $bucket = new Typecho_Widget_Helper_Form_Element_Text('bucket', NULL, '', _t('桶名称'));
        $form->addInput($bucket->addRule('required', _t('桶名称不能为空！')));

        $endpoint = new Typecho_Widget_Helper_Form_Element_Select('endpoint', array(
            'obs.cn-north-1.myhuaweicloud.com' => '华北-北京一',
            'obs.cn-north-4.myhuaweicloud.com' => '华北-北京四',
            'obs.cn-east-3.myhuaweicloud.com' => '华东-上海一',
            'obs.cn-east-2.myhuaweicloud.com' => '华东-上海二',
            'obs.cn-south-1.myhuaweicloud.com' => '华南-广州',
            'obs.cn-southwest-2.myhuaweicloud.com' => '西南-贵阳一',
			'obs.ap-southeast-1.myhuaweicloud.com' => '亚太-香港',
			'obs.ap-southeast-3.myhuaweicloud.com' => '亚太-新加坡',
			'obs.ap-southeast-2.myhuaweicloud.com' => '亚太-曼谷',
			'obs.af-south-1.myhuaweicloud.com' => '非洲-约翰内斯堡',
        ), '', _t('服务地址区域'));
        $form->addInput($endpoint->addRule('required', _t('服务地址区域不能为空！')));

        $domain = new Typecho_Widget_Helper_Form_Element_Text('domain', NULL, '', _t('自定义域名'), _t('可使用自定义域名（留空则使用默认域名）<br>例如：http://oss.example.com（需加上前面的 http:// 或 https://）'));
        $form->addInput($domain);
    }

    /* 个人用户的配置方法 */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 上传文件处理函数
     * @access public
     * @param array $file 上传的文件
     * @return mixed
     */
    public static function uploadHandle($file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $ext = self::getSafeName($file['name']);

        if (!self::checkFileType($ext) || Typecho_Common::isAppEngine()) {
            return false;
        }

        // 获取上传路径
        $date = new Typecho_Date();
        $filePath = (defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : self::UPLOAD_DIR) . '/' . $date->year . '/' . $date->month;
        // 获取文件名
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;

        $path = $filePath . '/' . $fileName;

        if (isset($file['tmp_name'])) {
            $SourceFile = $file['tmp_name'];
        } else if (isset($file['bytes'])) {
            $SourceFile = $file['bytes'];
        } else {
            return false;
        }

        //获取插件参数
        $options = Typecho_Widget::widget('Widget_Options')->plugin('ObsUpload');
        $obsClient = self::obsInit($options);

        try {
            $obsClient->putObject([
                'Bucket' => $options->bucket,
                'Key' => $path,
                'SourceFile' => $SourceFile
            ]);
        } catch (Exception $e) {
            return false;
        } finally {
            $obsClient->close();
        }

        //返回相对存储路径
        return array(
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => @Typecho_Common::mimeContentType($path)
        );
    }

    /**
     * 修改文件处理函数
     *
     * @access public
     * @param array $content 老文件
     * @param array $file 新上传的文件
     * @return mixed
     */
    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $ext = self::getSafeName($file['name']);

        if ($content['attachment']->type != $ext || Typecho_Common::isAppEngine()) {
            return false;
        }

        if (isset($file['tmp_name'])) {
            $SourceFile = $file['tmp_name'];
        } else if (isset($file['bytes'])) {
            $SourceFile = $file['bytes'];
        } else {
            return false;
        }

        //获取插件参数
        $options = Typecho_Widget::widget('Widget_Options')->plugin('ObsUpload');
        $obsClient = self::obsInit($options);

        try {
            $obsClient->putObject([
                'Bucket' => $options->bucket,
                'Key' => $content['attachment']->path,
                'SourceFile' => $SourceFile
            ]);
        } catch (Exception $e) {
            return false;
        } finally {
            $obsClient->close();
        }

        // if (!isset($file['size'])) {
        //     $file['size'] = filesize($path);
        // }

        //返回相对存储路径
        return array(
            'name' => $content['attachment']->name,
            'path' => $content['attachment']->path,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        );
    }

    /**
     * 删除文件
     *
     * @access public
     * @param array $content 文件相关信息
     * @return string
     */
    public static function deleteHandle(array $content)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('ObsUpload');
        $obsClient = self::obsInit($options);

        try {
            $obsClient->deleteObject([
                'Bucket' => $options->bucket,
                'Key' => $content['attachment']->path
            ]);
            return true;
        } catch (Exception $e) {
            return false;
        } finally {
            $obsClient->close();
        }
    }

    /**
     * 获取实际文件绝对访问路径
     *
     * @access public
     * @param array $content 文件相关信息
     * @return string
     */
    public static function attachmentHandle(array $content)
    {
        return Typecho_Common::url($content['attachment']->path, self::getDomain());
    }

    /**
     * 获取实际文件数据
     *
     * @access public
     * @param array $content
     * @return string
     */
    public static function attachmentDataHandle(array $content)
    {
        return file_get_contents(Typecho_Common::url($content['attachment']->path, self::getDomain()));
    }

    /**
     * OBS初始化
     *
     * @access private
     * @static
     * @param array $options
     * @return ObsClient
     */
    private static function obsInit($options)
    {
        // 创建ObsClient实例
        return new ObsClient([
            'key' => $options->ak,
            'secret' => $options->sk,
            'endpoint' => $options->endpoint,
            'socket_timeout' => 30,
            'connect_timeout' => 10
        ]);
    }

    /**
     * 检查文件名
     *
     * @access private
     * @param string $ext 扩展名
     * @return boolean
     */
    private static function checkFileType($ext)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        return in_array($ext, $options->allowedAttachmentTypes);
    }

    /**
     * 获取安全的文件名
     *
     * @param string $name
     * @static
     * @access private
     * @return string
     */
    private static function getSafeName(&$name)
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);

        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    /**
     * 获取访问域名
     *
     * @static
     * @access private
     * @return string
     */
    private static function getDomain()
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('ObsUpload');
        $domain = $options->domain;
        $http_type = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')) ? 'https://' : 'http://';
        if (empty($domain)) $domain = $http_type . $options->bucket . '.' . $options->endpoint;

        return $domain;
    }
}
