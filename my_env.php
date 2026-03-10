<?php

/**
 * File:
 * my_env.php
 * 
 * Description:
 * Set a Flutter environment, including Dart constants (like URI), application ID and Firebase configuration.
 * Note: Run this script always in the root of the Flutter project!
 * 
 * Firebase:
 * If you have Firebase, make sure that each environment has a set of the following files, where each file should be named as: [FILENAME]_[ENV].[EXT],
 * so for example android/app/google-services.json would be named as android/app/google-services_test.json and android/app/google-services_prod.json
 * if you have the 'test' and 'prod' environment defined.
 * - firebase.json
 * - android/app/google-services.json
 * - ios/Runner/GoogleService-Info.plist
 * - lib/firebase_options.dart
 * 
 * Usage to check current environment:
 * php my_env.php check
 * 
 * Usage to list all environments:
 * php my_env.php list
 * 
 * Usage to set environment (you will be asked to enter the environment):
 * php my_env.php
 */

///////////////////////////////////////////////////////////////////////////////
/// START constants

/**
 * The main config file.
 */
const MY_ENV_JSON_FILE = 'flutter-env.json';

/**
 * Android and iOS project files that contain the app id and should be modified if you have different app ids for different environments.
 */
const MY_FLUTTER_FILES_FOR_APP_ID = [
    'android/app/build.gradle.kts' => '/applicationId\s?=\s?"(.+)"/',
    'ios/Runner.xcodeproj/project.pbxproj' => '/PRODUCT_BUNDLE_IDENTIFIER\s?=\s?(.+);/'
];

/**
 * Firebase files that should be moved if you have different app ids for different environments.
 */
const MY_FIREBASE_FILES = [
    'firebase.json',
    'android/app/google-services.json',
    'ios/Runner/GoogleService-Info.plist',
    'lib/firebase_options.dart'
];

///////////////////////////////////////////////////////////////////////////////
/// START functions

/**
 * Dies with an error an newline.
 */
function my_error_die(string $error): void
{
    die("ERROR: $error\n");
}

/**
 * Returns the filename with app id appended. Used for the Firebase files.
 */
function my_get_filename_with_app_id(string $path, string $appId): string
{
    $lastIndex = strrpos($path, '.');
    return substr($path, 0, $lastIndex) . '_' . $appId . '.' . substr($path, $lastIndex + 1);
}

/**
 * Checks and optionally changes Android and iOS files with the defined app id.
 */
function my_handle_app_id(string $appId, bool $checkAppId, bool $replace): void
{
    foreach (MY_FLUTTER_FILES_FOR_APP_ID as $path => $regex) {
        $contents = file_get_contents($path);
        $matches = null;
        if (!preg_match($regex, $contents, $matches)) {
            my_error_die("Cannot find regex $regex in $path.");
        }
        if ($checkAppId) {
            if ($appId !== $matches[1]) {
                my_error_die("App id " . $matches[1] . " in file $path is not the same as the expected one $appId.");
            }
        }
        if ($replace) {
            $newValue = str_replace($matches[1], $appId, $matches[0]);
            $contents = str_replace($matches[0], $newValue, $contents);
            file_put_contents($path, $contents);
        }
    }
}

/**
 * Checks if the Firebase files exist. If appId is given also checks if the appended ones exist. And if checkDiff is set,
 * then also check if the current active ones (the ones without app id) are the same as the ones WITH the app id appended.
 */
function my_check_firebase_files(?string $appId = null, ?bool $checkDiff = false)
{
    foreach (MY_FIREBASE_FILES as $file) {
        if (!file_exists($file)) {
            my_error_die("Firebase file $file doesn't exist.");
        }
        if (!empty($appId)) {
            $envFile = my_get_filename_with_app_id($file, $appId);
            if (!file_exists($envFile)) {
                my_error_die("Firebase file $envFile doesn't exist.");
            }
            if (!empty($checkDiff)) {
                $md5Active = md5_file($file);
                $md5Env = md5_file($envFile);
                if ($md5Active !== $md5Env) {
                    my_error_die("Firebase file $file is different than $envFile.");
                }
            }
        }
    }
}

/**
 * Replaces the Firebase files with the active environment ones.
 * Note that this is only needed if you use different app ids for different environments.
 * In that case you should have every Firebase file appended with the app id, for example: firebase_my.app.id.json.
 */
function my_replace_firebase_files(string $appId)
{
    foreach (MY_FIREBASE_FILES as $targetFile) {
        $envFile = my_get_filename_with_app_id($targetFile, $appId);
        if (!is_readable($envFile)) {
            my_error_die("Cannot read $envFile.");
        }
        copy($envFile, $targetFile);
    }
}

/**
 * Writes the Dart config file with all defined environment variables in the 'dev' key of the flutter-env.json file.
 * By default the Dart filename will be 'my_env.dart' and the classname will be 'MyEnv'. This can be overridden in the flutter-env.json file.
 */
function my_write_dart_constants_file(string $env, array $envConfig): void
{
    $dartEnvLines = [
        "/// Env = $env",
        "/// Written by my_env.php - don't manually change this file!",
        "class " . $envConfig['env_class'] . " {"
    ];
    foreach ($envConfig['env'] as $key => $value) {
        $val = $value['value'];
        switch ($value['type']) {
            case 'String':
                $dartEnvLines[] = "\tstatic const $key = \"$val\";";
                break;
            case 'bool':
                $val = $val ? 'true' : 'false';
                $dartEnvLines[] = "\tstatic const $key = $val;";
                break;
            default:
                $dartEnvLines[] = "\tstatic const $key = $val;";
                break;
        }
    }
    $dartEnvLines[] = "}";
    file_put_contents('lib/' . $envConfig['env_file'], implode("\n", $dartEnvLines));
}

/**
 * Returns current selected environment.
 */
function get_current_active_env(string $envFile)
{
    $currentSetEnv = null;
    if (is_readable('lib/' . $envFile)) {
        $content = file_get_contents('lib/' . $envFile);
        $matches = null;
        if (preg_match('@/// Env = (.+)@', $content, $matches)) {
            $currentSetEnv = $matches[1];
        }
    }
    return $currentSetEnv;
}

/**
 * Return merged full config with the selected environment config.
 */
function my_get_env_config(array $fullConfig, string $env): array
{
    // Get the config for the selected environment by merging the default config with the selected environment.
    $envConfig = $fullConfig[$env];
    foreach ($fullConfig['default'] as $defaultKey1 => $defaultValue1) {
        if (!array_key_exists($defaultKey1, $envConfig)) {
            $envConfig[$defaultKey1] = $defaultValue1;
        }
        if ($defaultKey1 === 'env') {
            foreach ($defaultValue1 as $defaultEnvKey => $defaultEnvValue) {
                if (!array_key_exists($defaultEnvKey, $envConfig['env'])) {
                    $envConfig['env'][$defaultEnvKey] = $defaultEnvValue;
                }
                if (!array_key_exists('type', $envConfig['env'][$defaultEnvKey])) {
                    $envConfig['env'][$defaultEnvKey]['type'] = $defaultEnvValue['type'];
                }
            }
        }
    }
    if (array_key_exists('appId', $envConfig) && !array_key_exists('appId', $envConfig['env'])) {
        $envConfig['env']['appId'] = [
            'value' => $envConfig['appId'],
            'type' => 'String'
        ];
    }
    return $envConfig;
}

/**
 * List all defined environments.
 */
function my_global_list(array $environments, ?string $currentSetEnv = null)
{
    foreach ($environments as $environment) {
        if ($environment === $currentSetEnv) {
            echo "$environment *\n";
        } else {
            echo "$environment\n";
        }
    }
}

/**
 * Check current environment.
 */
function my_global_check(array $fullConfig, ?string $env = null)
{
    if (empty($env)) {
        my_error_die("No environment set yet, first set an environment before calling the check command.");
    }
    $envConfig = my_get_env_config($fullConfig, $env);

    // Check Dart file with constants
    $envFile = $envConfig['env_file'];
    $dartFileContents = file_get_contents('lib/' . $envFile);
    foreach ($envConfig['env'] as $key => $value) {
        $val = $value['value'];
        switch ($value['type']) {
            case 'String':
                // Note that a string can contain special regex characters (like *), so these need to be escaped.
                $val = preg_quote($val, '@');
                if (!preg_match("@static const $key = \"$val\";@", $dartFileContents)) {
                    my_error_die("Cannot find '$key' with value '$val' in $envFile.");
                }
                break;
            case 'bool':
                $val = $val ? 'true' : 'false';
                if (!preg_match("@static const $key = $val;@", $dartFileContents)) {
                    my_error_die("Cannot find '$key' with value '$val' in $envFile.");
                }
                break;
            default:
                if (!preg_match("@static const $key = $val;@", $dartFileContents)) {
                    my_error_die("Cannot find '$key' with value '$val' in $envFile.");
                }
                break;
        }
    }

    $appId = $envConfig['appId'] ?? null;

    if (!empty($appId)) {
        my_handle_app_id($appId, checkAppId: true, replace: false);
        if (!empty($envConfig['firebaseManage'])) {
            my_check_firebase_files($appId, true);
        }
    }
}

/**
 * Switch to environment.
 */
function my_global_switch(array $fullConfig, array $environments)
{
    // Now we know we don't have a known command line argument, so ask for environment to set.
    $env = readline("Enter environment (" . implode(', ', $environments) . "): ");
    if (!in_array($env, $environments)) {
        my_error_die("Invalid environment.");
    }

    $envConfig = my_get_env_config($fullConfig, $env);

    $appId = $envConfig['appId'] ?? null;

    /// Whether we need to change the Firebase files. So only needed if this project has Firebase files
    /// AND we use different app id's between environments.
    $manageFirebase = !empty($envConfig['firebaseManage']);
    if ($manageFirebase) {
        my_check_firebase_files($appId);
    }

    $answerContinueYesNo = readline("Continue setting the environment to $env " . ($manageFirebase ? "*WITH*" : "without") . " Firebase (yes/no)? ");
    if ($answerContinueYesNo !== 'yes') {
        my_error_die("Stopped.");
    }

    // START writing changes
    my_write_dart_constants_file($env, $envConfig);
    if (!empty($appId)) {
        my_handle_app_id($appId, checkAppId: false, replace: true);
        if ($manageFirebase) {
            my_replace_firebase_files($appId);
        }
    }

    my_global_check($fullConfig, $env);

    die("OK, environment $env has been set.\n");
}

/**
 * Build Flutter app.
 */
function my_global_build(array $fullConfig, string $currentSetEnv)
{
    my_global_check($fullConfig, $currentSetEnv);

    $platform = readline("Platform (ios/android)? ");
    if (!in_array($platform, ['ios', 'android'])) {
        my_error_die('Unknown platform.');
    }

    switch ($platform) {
        case 'ios':
            if (readline("Build for iOS with environment '$currentSetEnv' (yes/no)? ") !== 'yes') {
                die("Exiting\n");
            }
            passthru('flutter build ipa', $resultCode);
            if (!empty($resultCode)) {
                my_error_die("Couldn't built for iOS, check output for error.");
            }
            break;
        case 'android':
            $output = readline("Output file? (apk/appbundle)? ");
            if (!in_array($output, ['apk', 'appbundle'])) {
                my_error_die('Unknown output file.');
            }
            if (readline("Build $output for Android with environment '$currentSetEnv' (yes/no)? ") !== 'yes') {
                die("Exiting\n");
            }
            passthru("flutter build $output", $resultCode);
            if (!empty($resultCode)) {
                my_error_die("Couldn't built for Android, check output for error.");
            }
            break;
    }
    die("OK, app has been built.\n");
}

/**
 * Start of script, checks input and runs associated code.
 */
function my_init($argv)
{
    ///////////////////////////////////////////////////////////////////////////////
    /// START global checks

    if (!file_exists('./pubspec.yaml')) {
        my_error_die("Run this script in the root directory of your Flutter project.");
    }

    if (!is_readable(MY_ENV_JSON_FILE)) {
        my_error_die("Config file " . MY_ENV_JSON_FILE . " does not exist.");
    }

    /**
     * Parse the `flutter-env.json` file. MUST contain the `default` key with all the default configuration.
     * CAN contain other environments as keys that can override default or add new configurations.
     * The `default` can contain some special keys that other environments don't have:
     * - `env_file` - File to write the constants to. If empty, then it will be `my_env.dart`.
     * - `env_class` - Class that the constants belong to. If empty, then it will be `MyEnv`.
     * The default and other environments can contain the following keys:
     * - `appId` - Only needed if environments have different app id's.
     * - `firebaseManage` - Whether this script should manage the Firebase config files.
     * - `env` - This is an object where each key specifies a constant and must contain 2 inner keys: `value` and `type`.
     */
    $fullConfig = json_decode(file_get_contents(MY_ENV_JSON_FILE), true);
    $environments = array_keys($fullConfig);

    // Add default values if they are not set
    if (!array_key_exists('env_file', $fullConfig['default'])) {
        $fullConfig['default']['env_file'] = 'my_env.dart';
    }
    if (!array_key_exists('env_class', $fullConfig['default'])) {
        $fullConfig['default']['env_class'] = 'MyEnv';
    }

    $currentSetEnv = get_current_active_env($fullConfig['default']['env_file']);

    $firstArgument = $argv[1] ?? null;
    if (empty($firstArgument)) {
        my_error_die("Specify command: build [platform], switch, list, check.");
        exit;
    }
    if (!empty($firstArgument)) {
        switch ($firstArgument) {
            case 'build':
                my_global_build(fullConfig: $fullConfig, currentSetEnv: $currentSetEnv);
                break;
            case 'switch':
                my_global_switch($fullConfig, $environments);
                break;
            case 'list':
                my_global_list($environments, $currentSetEnv);
                break;
            case 'check':
                my_global_check($fullConfig, $currentSetEnv);
                die("OK check for $currentSetEnv\n");
                break;
            default:
                my_error_die("Missing command.");
                break;
        }
        exit;
    }
}

/**
 * Start the script.
 */
my_init($argv);
