const trimTrailingSlash = (value) => String(value).replace(/\/+$/, "");

async function resolvePublishButton(browser, timeoutMs) {
  const selectors = [
    '[data-test="publish-version-button"]',
    'button=Publish Version',
    'button*=Publish Version',
  ];

  for (const selector of selectors) {
    const candidate = await browser.$(selector);
    if (await candidate.isExisting()) {
      await candidate.waitForDisplayed({ timeout: timeoutMs });
      return candidate;
    }
  }

  await browser.waitUntil(
    async () => {
      const button = await browser.$('[data-test="publish-version-button"]');
      return button.isExisting();
    },
    {
      timeout: timeoutMs,
      interval: 250,
      timeoutMsg: "Publish button was not found on version page.",
    },
  );

  const button = await browser.$('[data-test="publish-version-button"]');
  await button.waitForDisplayed({ timeout: timeoutMs });
  return button;
}

export async function loginAsArchitectAdmin(
  browser,
  { baseUrl, email, password, timeoutMs = 20000 },
) {
  const normalizedBase = trimTrailingSlash(baseUrl);
  await browser.url(`${normalizedBase}/login`);

  const emailInput = await browser.$('[data-test="email"]');
  await emailInput.waitForDisplayed({ timeout: timeoutMs });
  await emailInput.click();
  await emailInput.setValue(String(email));

  const passwordInput = await browser.$('[data-test="password"]');
  await passwordInput.waitForDisplayed({ timeout: timeoutMs });
  await passwordInput.click();
  await passwordInput.setValue(String(password));

  const loginButton = await browser.$('[data-test="login-button"]');
  await loginButton.waitForDisplayed({ timeout: timeoutMs });
  await loginButton.click();

  await browser.waitUntil(
    async () => {
      const url = await browser.getUrl();
      return !url.includes("/login");
    },
    {
      timeout: timeoutMs,
      interval: 300,
      timeoutMsg: "Architect admin login did not navigate away from /login.",
    },
  );
}

export async function publishVersionFromArchitect(
  browser,
  { baseUrl, surveyId, versionId, timeoutMs = 25000 },
) {
  const normalizedBase = trimTrailingSlash(baseUrl);
  await browser.url(`${normalizedBase}/admin/surveys/${surveyId}/versions/${versionId}`);

  const publishButton = await resolvePublishButton(browser, timeoutMs);

  const enabled = await publishButton.isEnabled();
  if (!enabled) {
    const currentUrl = await browser.getUrl();
    throw new Error(
      `Publish button is disabled for survey ${surveyId} version ${versionId}. URL: ${currentUrl}. Ensure scenario version is draft.`,
    );
  }

  await publishButton.click();

  await browser.waitUntil(
    async () => {
      const button = await browser.$('[data-test="publish-version-button"]');
      if (!(await button.isExisting()) || !(await button.isDisplayed())) {
        return false;
      }
      return !(await button.isEnabled());
    },
    {
      timeout: timeoutMs,
      interval: 350,
      timeoutMsg:
        "Publish action did not complete (publish button stayed enabled).",
    },
  );
}
