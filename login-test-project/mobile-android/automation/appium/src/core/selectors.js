export const byTag = (tag) => `id=${tag}`;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const appPackage = process.env.APPIUM_APP_PACKAGE ?? "com.lolsurvey.mobile";

const normalizeTag = (tag) => {
  if (typeof tag !== "string") {
    return "";
  }
  const normalized = tag.trim();
  if (!normalized || normalized === "undefined" || normalized === "null") {
    return "";
  }
  return normalized;
};

const selectorsForTag = (tag) => {
  const safeTag = normalizeTag(tag);
  if (!safeTag) {
    return [];
  }

  return [
    byTag(safeTag),
    `id=${appPackage}:id/${safeTag}`,
    `android=new UiSelector().resourceId("${safeTag}")`,
    `android=new UiSelector().resourceId("${appPackage}:id/${safeTag}")`,
    `~${safeTag}`,
  ];
};

const findVisibleElement = async (driver, tag) => {
  const selectors = selectorsForTag(tag);
  for (const selector of selectors) {
    try {
      const element = await driver.$(selector);
      if (!(await element.isExisting())) {
        continue;
      }
      if (await element.isDisplayed()) {
        return element;
      }
    } catch {
      // Try next selector candidate.
    }
  }
  return null;
};

export const waitForVisible = async (driver, tag, timeoutMs = 15000) => {
  const safeTag = normalizeTag(tag);
  if (!safeTag) {
    throw new Error(`waitForVisible received invalid tag: ${String(tag)}`);
  }

  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const visible = await findVisibleElement(driver, safeTag);
    if (visible) {
      return visible;
    }
    await sleep(250);
  }
  throw new Error(`element ("id=${safeTag}") still not displayed after ${timeoutMs}ms`);
};

export const waitForAnyVisible = async (driver, tags, timeoutMs = 15000) => {
  const normalizedTags = tags.map((tag) => normalizeTag(tag)).filter(Boolean);
  if (normalizedTags.length === 0) {
    throw new Error("waitForAnyVisible received no valid tags.");
  }

  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    for (const tag of normalizedTags) {
      const visible = await findVisibleElement(driver, tag);
      if (visible) {
        return { tag, element: visible };
      }
    }
    await sleep(250);
  }
  throw new Error(
    `none of tags became visible within ${timeoutMs}ms: ${normalizedTags.join(", ")}`,
  );
};

export const maybeElement = async (driver, tag) => {
  const selectors = selectorsForTag(tag);
  if (selectors.length === 0) {
    return {
      isExisting: async () => false,
      isDisplayed: async () => false,
      getText: async () => "",
    };
  }

  for (const selector of selectors) {
    try {
      const element = await driver.$(selector);
      if (await element.isExisting()) {
        return element;
      }
    } catch {
      // Ignore and try next selector candidate.
    }
  }
  return driver.$(selectors[0]);
};
