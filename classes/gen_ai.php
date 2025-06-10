<?php

namespace block_learningassist;

use core\di;
use core\exception\coding_exception;
use core_ai\manager;
use Exception;


/**
 * This class provides a generic interface for making AI calls using the built-in Moodle AI providers.
 *
 * @package    block_learningassist
 * @copyright  2025 YOUR NAME <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class gen_ai
{

    /**
     * Uses the built-in Moodle AI providers and placements
     * @param string $prompt
     * @param array $history
     * @param string $lang
     * @param int|null $contextid
     * @param int|null $userid
     * @return string|null
     * @throws Exception
     */
    public static function make_call(string $prompt, array $history, string $lang = 'en', ?int $contextid = null, ?int $userid = null): ?string
    {
        global $CFG, $USER;

        // Always return the response in the language of the course
        $prompt .= "\n\nYou must return the response in the language based on this language code: $lang.\n\n";

        $messages = array(
            ...$history,
            array(
                'role' => 'user',
                'content' => $prompt
            ),
        );

        // Get AI manager.
        $manager = di::get(manager::class);

        // Get provider instances
        $provider_instances = $manager->get_provider_instances();

        // Find the first provider that supports generate_text.
        $provider_instance = null;
        foreach ($provider_instances as $instance) {
            if (!empty($instance->actionconfig['core_ai\aiactions\generate_text'])) {
                $provider_instance = $instance;
                break;
            }
        }

        if (empty($provider_instance)) {
            throw new Exception('No provider instance with generate_text action found');
        }

        // Use system context if not provided
        if ($contextid === null) {
            $contextid = \context_system::instance()->id;
        }
        // Use current user if not provided
        if ($userid === null) {
            $userid = $USER->id;
        }

        // Get the provider type and call the appropriate method
        $provider_type = self::get_provider_type($provider_instance);
        $response = self::call_provider($provider_type, $messages, $provider_instance, $contextid, $userid);

        return markdown_to_html($response);
    }

    /**
     * Determine the provider type based on the provider instance
     * @param $provider_instance \core_ai\provider_instance
     * @return string
     */
    private static function get_provider_type($provider_instance): string
    {
        // Get the provider identifier - it might be a string or object
        $provider_identifier = '';

        if (is_object($provider_instance->provider)) {
            $provider_identifier = get_class($provider_instance->provider);
        } elseif (is_string($provider_instance->provider)) {
            $provider_identifier = $provider_instance->provider;
        } elseif (isset($provider_instance->providername)) {
            $provider_identifier = $provider_instance->providername;
        } elseif (isset($provider_instance->name)) {
            $provider_identifier = $provider_instance->name;
        }

        // Convert to lowercase for easier matching
        $provider_identifier = strtolower($provider_identifier);

        // Extract provider name from identifier
        if (strpos($provider_identifier, 'azureopenai') !== false || strpos($provider_identifier, 'azure_openai') !== false) {
            return 'azure_openai';
        } elseif (strpos($provider_identifier, 'aiprovider_ollama') !== false || strpos($provider_identifier, 'ollama') !== false) {
            // Add support for Ollama provider
            return 'ollama';
        } elseif (strpos($provider_identifier, 'openai') !== false) {
            return 'openai';
        }

        // You can add more provider types here as needed
        // For example: anthropic, google, etc.

        throw new Exception('Unsupported provider type: ' . $provider_identifier);
    }

    /**
     * Call the appropriate provider based on type
     * @param string $provider_type
     * @param array $messages
     * @param $provider_instance \core_ai\provider_instance
     * @param int $contextid
     * @param int $userid
     * @return string
     * @throws Exception
     */
    private static function call_provider(string $provider_type, array $messages, $provider_instance, int $contextid, int $userid): string
    {
        switch ($provider_type) {
            case 'azure_openai':
                $config = self::get_azure_openai_config($provider_instance);
                return self::azure_openai_chat(
                    $messages,
                    $config->apikey,
                    $config->endpoint,
                    $config->deployment,
                    $config->apiversion
                );
            case 'openai':
                $config = self::get_openai_config($provider_instance);
                return self::openai_chat(
                    $messages,
                    $config->apikey,
                    $config->model
                );
            case 'ollama':
                // Use the core_ai action system to generate text with Ollama
                return self::ollama_generate_text($messages, $provider_instance, $contextid, $userid);
            default:
                throw new Exception('Unsupported provider type: ' . $provider_type);
        }
    }

    /**
     * Get Azure OpenAI configuration from provider instance
     * @param $provider_instance \core_ai\provider_instance
     * @return \stdClass
     */
    private static function get_azure_openai_config($provider_instance): \stdClass
    {
        $config = new \stdClass();
        $config->apikey = $provider_instance->config['apikey'];
        $config->endpoint = $provider_instance->config['endpoint'];

        foreach ($provider_instance->actionconfig as $key => $action_config) {
            if ($key == 'core_ai\aiactions\generate_text') {
                $config->deployment = $provider_instance->actionconfig[$key]['settings']['deployment'];
                $config->apiversion = $provider_instance->actionconfig[$key]['settings']['apiversion'];
                break;
            }
        }
        return $config;
    }

    /**
     * Get OpenAI configuration from provider instance
     * @param $provider_instance \core_ai\provider_instance
     * @return \stdClass
     */
    private static function get_openai_config($provider_instance): \stdClass
    {
        $config = new \stdClass();
        $config->apikey = $provider_instance->config['apikey'];

        // Get model from action config, or use default
        $config->model = 'gpt-3.5-turbo'; // default
        foreach ($provider_instance->actionconfig as $key => $action_config) {
            if ($key == 'core_ai\aiactions\generate_text') {
                $config->model = $provider_instance->actionconfig[$key]['settings']['model'] ?? 'gpt-3.5-turbo';
                break;
            }
        }
        return $config;
    }

    /**
     * This function creates an Azure OpenAI chat session
     */
    public static function azure_openai_chat($messages, $api_key, $endpoint, $deployment_id, $api_version): string
    {
        $url = $endpoint . "/openai/deployments/$deployment_id/chat/completions?api-version=$api_version";

        $headers = [
            "Content-Type: application/json",
            "api-key: $api_key"
        ];

        $data = [
            "messages" => $messages,
            "max_tokens" => 4096,
            "temperature" => 0.7
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            curl_close($ch);
            throw new Exception('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);

        if ($http_code !== 200) {
            throw new Exception('Azure OpenAI API error. HTTP Code: ' . $http_code . ', Response: ' . $result);
        }

        $response = json_decode($result, true);

        if (!isset($response['choices'][0]['message']['content'])) {
            throw new Exception('Invalid response from Azure OpenAI API');
        }

        return $response['choices'][0]['message']['content'];
    }

    /**
     * This function creates an OpenAI chat session
     */
    public static function openai_chat($messages, $api_key, $model = 'gpt-3.5-turbo'): string
    {
        $url = 'https://api.openai.com/v1/chat/completions';

        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer $api_key"
        ];

        $data = [
            "model" => $model,
            "messages" => $messages,
            "max_tokens" => 4096,
            "temperature" => 0.7
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            curl_close($ch);
            throw new Exception('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);

        if ($http_code !== 200) {
            throw new Exception('OpenAI API error. HTTP Code: ' . $http_code . ', Response: ' . $result);
        }

        $response = json_decode($result, true);

        if (!isset($response['choices'][0]['message']['content'])) {
            throw new Exception('Invalid response from OpenAI API');
        }

        return $response['choices'][0]['message']['content'];
    }

    /**
     * Get the cache instance for chat history.
     * @return \cache_application
     */
    private static function get_cache(): \cache_application
    {
        return \cache::make('block_learningassist', 'chat_history');
    }

    /**
     * Get chat history for a specific chatid.
     * @param string $chatid
     * @return array
     * @throws coding_exception
     */
    public static function get_history(string $chatid): array
    {
        $cache = self::get_cache();
        $key = self::normalize_cache_key($chatid);
        $history = $cache->get($key);
        return ($history === false || !is_array($history)) ? [] : $history;
    }

    private static function normalize_cache_key(string $key): string
    {
        return sha1($key);
    }

    /**
     * Set full history for a specific chatid.
     * @param string $chatid
     * @param array $history
     */
    public static function set_history(string $chatid, array $history): void
    {
        $cache = self::get_cache();
        $key = self::normalize_cache_key($chatid);
        $cache->set($key, $history);
    }

    /**
     * Clear the chat history for a specific chatid.
     * @param string $chatid
     */
    public static function clear_history(string $chatid): void
    {
        $cache = self::get_cache();
        $key = self::normalize_cache_key($chatid);
        $cache->delete($key);
    }

    /**
     * Add an entry to the chat history.
     * @param string $chatid
     * @param string $role
     * @param string $content
     * @throws coding_exception
     */
    public static function add_to_history(string $chatid, string $role, string $content): void
    {
        $history = self::get_history($chatid);
        $history[] = ['role' => $role, 'content' => $content];
        self::set_history($chatid, $history);
    }

    /**
     * Generate text using the Ollama provider via the core_ai action system.
     * @param array $messages
     * @param $provider_instance \core_ai\provider_instance
     * @param int $contextid
     * @param int $userid
     * @return string
     * @throws Exception
     */
    private static function ollama_generate_text(array $messages, $provider_instance, int $contextid, int $userid): string
    {
        // Find the generate_text action config
        $actionkey = 'core_ai\\aiactions\\generate_text';
        if (empty($provider_instance->actionconfig[$actionkey])) {
            throw new Exception('Ollama provider instance missing generate_text action config');
        }
        $actionconfig = $provider_instance->actionconfig[$actionkey]['settings'] ?? [];
        // Compose the prompt from the messages array (concatenate all user/assistant content)
        $prompt = '';
        foreach ($messages as $msg) {
            if (!empty($msg['content'])) {
                $prompt .= $msg['content'] . "\n";
            }
        }
        // Create the action object with correct argument order
        $action = new \core_ai\aiactions\generate_text(
            $contextid,
            $userid,
            $prompt
        );
        // Use the manager to process the action
        $manager = \core\di::get(\core_ai\manager::class);
        $response = $manager->process_action($action);
        if (empty($response) || empty($response->generatedcontent)) {
            throw new Exception('Ollama provider did not return generated content');
        }
        return $response->generatedcontent;
    }

}
