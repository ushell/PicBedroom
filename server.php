<?php
class FileServer
{
    private $request;
    private $response;
    private $server;
    private $redis;

    private $ip     = "0.0.0.0";
    private $port   = "8080";

    private $uri    = '';

    private $data   = "/data/images";

    private $log    = "/tmp/swoole_http_runtime.log";

    private $domain = "your_domain";

    private $daemonize = true;

    private $worker_num = 4;

    private $image_map = ["png", "jpg", "jpeg", "gif"];

    //文件大小限制
    const IMAGE_MAX_SIZE = 5 * 1024 * 1024;

    public function init()
    {
        $this->server = new swoole_http_server($this->ip, $this->port);

        $this->server->set([
            'daemonize'     => $this->daemonize,
            'worker_num'    => $this->worker_num,
            'log_file'      => $this->log,
        ]);

        $this->server->on('request', [$this, "request"]);

        $this->redis = new Redis();
        $this->redis->connect("127.0.0.1", 6379);
        // $this->redis->auth("your_redis_password");
    }

    private function auth()
    {
        $token = $this->request->post("token");
        //TODO
    }

    public function request(swoole_http_request $request, swoole_http_response $response)
    {
        $this->request = $request;
        $this->response = $response;

        $this->uri = $this->request->server['request_uri'];

        $resp = ['code' => 0, 'message' => ''];

        //上传模块
        if (substr($this->uri, 1, 6) == "upload") {
            $resp = $this->upload();
        }

        //文件模块
        if (substr($this->uri, 1, 4) == "file") {
            $resp = $this->file();
        }

        $this->response->status(200);
        $this->response->end(json_encode($resp));
    }

    private function file()
    {
        $suffix     = pathinfo($this->uri, PATHINFO_EXTENSION);
        $filename   = pathinfo($this->uri, PATHINFO_FILENAME);

        if (! in_array($suffix, $this->image_map))
        {
           return ['code' => '404', 'message' => '文件格式错误']; 
        }

        $filename = $this->cache("get", $filename);

        if (empty($filename))
        {
            return ['code' => '404', 'message' => '文件不存在'];
        }

        $mime = sprintf("image/%s", $suffix);

        $this->response->header("Content-Type", $mime);

        $this->response->sendfile($filename);
    }

    private function upload()
    {
        //上传文件字段
        if (empty($this->request->files["filename"]))
        {
            return ['code' => '404', 'message' => '数据结构错误'];
        }

        $file = $this->request->files["filename"];

        if ($file["size"] > self::IMAGE_MAX_SIZE)
        {
            return ['code' => '403', 'message' => '文件过大'];
        }

        $suffix = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (!in_array($suffix, $this->image_map))
        {
            return ['code' => '403', 'message' => '文件格式错误'];
        }

        $meta = @getimagesize($file["tmp_name"]);

        if ($meta == false)
        {
             return ['code' => '403', 'message' => '文件非法'];
        }

        //新文件名
        $path = sprintf("%s/%s", $this->data, date("Y-m-d"));
        if (!file_exists($path))
        {
            mkdir($path, 0755);
        }

        $filename = sprintf("%s/%s.%s", $path, date("Ymdhis", time()), $suffix);

        if (!move_uploaded_file($file["tmp_name"], $filename))
        {
            return ['code' => '503', 'message' => '内部错误'];
        }

        $hash = $this->cache("save", $filename);

        $uri = sprintf("%s/%s", $this->domain, $hash);

        return ["code" => 0, "data" => "url" => $uri]];
    }

    private function cache($action = "save", $file)
    {
        if ($action == "save" && $file)
        {
            $suffix = pathinfo($file, PATHINFO_EXTENSION);

            //唯一ID
            $hash = md5($file) . substr(uniqid(mt_rand()), 1, 18);

            $this->redis->set($hash, $file);

            return sprintf("%s.%s", $hash, $suffix);
        }

        if ($action == "get" && $file)
        {
            $hash = pathinfo($file, PATHINFO_FILENAME);

            $filename = $this->redis->get($hash);

            return $filename;
        }

        return "";
    }

    public function run()
    {
        try {
            $this->init();

            $this->server->start();
        } catch (Exception $e) {
            file_put_contents($this->log, json_encode($e->getMessages()).PHP_EOL, FILE_APPEND);

            exit(-1);
        }
    }
}

$server = new FileServer;
$server->run();


