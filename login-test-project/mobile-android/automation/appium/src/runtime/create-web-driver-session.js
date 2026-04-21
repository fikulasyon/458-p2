import { remote } from "webdriverio";

const intFromEnv = (key, fallback) => {
  const value = process.env[key];
  if (!value) {
    return fallback;
  }
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) ? parsed : fallback;
};

const parseBooleanEnv = (key, fallback = false) => {
  const raw = process.env[key];
  if (raw === undefined) {
    return fallback;
  }

  const normalized = String(raw).trim().toLowerCase();
  return normalized === "1" || normalized === "true" || normalized === "yes";
};

const chromeOptionsFromEnv = () => {
  const args = [];
  if (parseBooleanEnv("SELENIUM_HEADLESS", false)) {
    args.push("--headless=new");
    args.push("--disable-gpu");
  }

  if (parseBooleanEnv("SELENIUM_NO_SANDBOX", false)) {
    args.push("--no-sandbox");
  }

  return args.length > 0 ? { args } : undefined;
};

export async function createWebDriverSession() {
  const browserName = process.env.SELENIUM_BROWSER ?? "chrome";
  const options = {
    protocol: process.env.SELENIUM_PROTOCOL ?? "http",
    hostname: process.env.SELENIUM_HOST ?? "127.0.0.1",
    port: intFromEnv("SELENIUM_PORT", 4444),
    path: process.env.SELENIUM_PATH ?? "/wd/hub",
    capabilities: {
      browserName,
    },
    connectionRetryCount: 1,
    connectionRetryTimeout: 90000,
    logLevel: process.env.SELENIUM_LOG_LEVEL ?? "info",
  };

  const chromeOptions = chromeOptionsFromEnv();
  if (chromeOptions && browserName.toLowerCase() === "chrome") {
    options.capabilities["goog:chromeOptions"] = chromeOptions;
  }

  return remote(options);
}

