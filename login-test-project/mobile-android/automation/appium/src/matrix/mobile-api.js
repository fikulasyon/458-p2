const trimTrailingSlash = (value) => String(value).replace(/\/+$/, "");

const baseUrl = () => trimTrailingSlash(process.env.BACKEND_BASE_URL ?? "http://127.0.0.1:8000");

const requestJson = async (path, { method = "GET", token, body } = {}) => {
  const url = `${baseUrl()}${path.startsWith("/") ? path : `/${path}`}`;
  const headers = {
    Accept: "application/json",
  };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  let payload = undefined;
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    payload = JSON.stringify(body);
  }

  const response = await fetch(url, {
    method,
    headers,
    body: payload,
  });

  const text = await response.text();
  let json = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = null;
  }

  if (!response.ok) {
    throw new Error(
      [
        `API request failed: ${method} ${url}`,
        `HTTP ${response.status}`,
        json ? `Body: ${JSON.stringify(json)}` : `Body(raw): ${text}`,
      ].join("\n"),
    );
  }

  return json;
};

export async function loginMobileApi({ email, password, deviceName = "appium-matrix-suite" }) {
  const json = await requestJson("/api/mobile/login", {
    method: "POST",
    body: {
      email,
      password,
      device_name: deviceName,
    },
  });

  if (!json?.access_token) {
    throw new Error(`Mobile login did not return access_token: ${JSON.stringify(json)}`);
  }

  return json.access_token;
}

export async function startSurveySession({ token, surveyId }) {
  if (!surveyId) {
    throw new Error("surveyId is required for startSurveySession.");
  }

  return requestJson(`/api/mobile/surveys/${surveyId}/sessions/start`, {
    method: "POST",
    token,
  });
}

export async function fetchSessionState({ token, sessionId }) {
  if (!sessionId) {
    throw new Error("sessionId is required for fetchSessionState.");
  }

  return requestJson(`/api/mobile/sessions/${sessionId}/state`, {
    method: "GET",
    token,
  });
}
