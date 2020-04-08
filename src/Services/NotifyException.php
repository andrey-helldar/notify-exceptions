<?php

namespace Helldar\Notifex\Services;

use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Jaybizzle\CrawlerDetect\CrawlerDetect;

class NotifyException
{
    /**
     * @var string
     */
    private $queue;

    /**
     * The exception handler implementation.
     *
     * @var \Helldar\Notifex\Services\ExceptionHandler
     */
    private $handler;

    /**
     * @var \Exception
     */
    private $exception;

    public function __construct(ExceptionHandler $handler)
    {
        $this->queue = Config::get('notifex.queue', 'default');

        $this->handler = $handler;
    }

    /**
     * @param \Exception $exception
     */
    public function send(Exception $exception)
    {
        try {
            if ($this->isIgnoreBots() || !$this->isEnabled()) {
                return;
            }

            $this->exception = $exception;

            $this->sendEmail();
            $this->sendJobs();
        } catch (Exception $exception) {
            $this->log($exception, __FUNCTION__);
        }
    }

    protected function sendEmail()
    {
        try {
            if (Config::get('notifex.email.enabled', true)) {
                new Email($this->handler, $this->exception);
            }
        } catch (Exception $exception) {
            $this->log($exception, __FUNCTION__);
        }
    }

    protected function sendJobs()
    {
        try {
            $jobs = (array) Config::get('notifex.jobs', []);

            if (!count($jobs)) {
                return;
            }

            $classname       = (string) get_class($this->exception);
            $message         = (string) $this->exception->getMessage();
            $file            = (string) $this->exception->getFile();
            $line            = (int) $this->exception->getLine();
            $trace_as_string = (string) $this->exception->getTraceAsString();

            foreach ($jobs as $job => $params) {
                try {
                    if ($params['enabled'] ?? true) {
                        $job = is_numeric($job) ? $params : $job;

                        dispatch(new $job($classname, $message, $file, $line, $trace_as_string))
                            ->onQueue($this->queue);
                    }
                } catch (Exception $exception) {
                    $this->log($exception, __FUNCTION__);
                }
            }
        } catch (Exception $exception) {
            $this->log($exception, __FUNCTION__);
        }
    }

    private function isIgnoreBots(): bool
    {
        $ignore_bots = Config::get('notifex.ignore_bots', true);

        if (!$ignore_bots) {
            return false;
        }

        $crawler = new CrawlerDetect();

        return $crawler->isCrawler($this->userAgent());
    }

    private function isEnabled()
    {
        $email = Config::get('notifex.email.enabled', true);

        $jobs = array_filter(Config::get('notifex.jobs', []), function ($item) {
            return $item['enabled'] ?? true == true;
        });

        return $email == true || count($jobs) > 0;
    }

    private function userAgent(): ?string
    {
        return app('request')->userAgent() ?? null;
    }

    private function log(Exception $exception, string $function_name)
    {
        Log::error(sprintf(
            'Exception thrown in %s::%s when capturing an exception',
            get_class(),
            $function_name
        ));

        Log::error($exception);
    }
}
