import { remote } from "webdriverio";

const intFromEnv = (key, fallback) => {
  const value = process.env[key];
  if (!value) {
    return fallback;
  }
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) ? parsed : fallback;
};

export async function createDriverSession() {
  const options = {
    protocol: process.env.APPIUM_PROTOCOL ?? "http",
    hostname: process.env.APPIUM_HOST ?? "127.0.0.1",
    port: intFromEnv("APPIUM_PORT", 4723),
    path: process.env.APPIUM_PATH ?? "/",
    capabilities: {
      platformName: "Android",
      "appium:automationName": "UiAutomator2",
      "appium:deviceName": process.env.APPIUM_DEVICE_NAME ?? "Android Emulator",
      "appium:appPackage": process.env.APPIUM_APP_PACKAGE ?? "com.lolsurvey.mobile",
      "appium:appActivity": process.env.APPIUM_APP_ACTIVITY ?? "com.lolsurvey.mobile.MainActivity",
      "appium:noReset": (process.env.APPIUM_NO_RESET ?? "false") === "true",
      "appium:newCommandTimeout": intFromEnv("APPIUM_NEW_COMMAND_TIMEOUT", 180),
    },
    connectionRetryCount: 2,
    connectionRetryTimeout: 120000,
    logLevel: process.env.APPIUM_LOG_LEVEL ?? "info",
  };

  return remote(options);
}
