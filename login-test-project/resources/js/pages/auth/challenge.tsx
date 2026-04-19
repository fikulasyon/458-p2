import React, { useEffect, useMemo, useState } from "react";
import { Head, useForm } from "@inertiajs/react";

type ChallengeStatus = {
  state: string | null;
  challenge_attempts: number;
  challenge_locked_until: string | null; // ISO string
  otp_expires_at: string | null; // ISO string
};

function minutesRemaining(iso: string | null): number | null {
  if (!iso) return null;
  const until = new Date(iso).getTime();
  const now = Date.now();
  const diffMs = until - now;
  if (diffMs <= 0) return 0;
  return Math.ceil(diffMs / 60000);
}

export default function Challenge() {
  const form = useForm({ code: "" });

  const [status, setStatus] = useState<ChallengeStatus | null>(null);
  const [loading, setLoading] = useState<boolean>(true);

  async function loadStatus() {
    try {
      setLoading(true);
      const res = await fetch("/challenge/status", {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
      });
      const data = (await res.json()) as ChallengeStatus;
      setStatus(data);
    } catch (e) {
      // If something goes wrong, keep UI usable but show minimal info
      setStatus(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadStatus();
    const id = window.setInterval(loadStatus, 5000);
    return () => window.clearInterval(id);
  }, []);

  const lockedMins = useMemo(
    () => minutesRemaining(status?.challenge_locked_until ?? null),
    [status?.challenge_locked_until]
  );

  const isLocked =
    status?.state === "Challenged_locked" && lockedMins !== null && lockedMins > 0;

  const lockText = useMemo(() => {
    if (!status) return null;
    if (status.state !== "Challenged_locked") return null;

    if (lockedMins === null) return "Challenge is locked.";
    if (lockedMins <= 0) return "Challenge lock expired. You can try again now.";
    return `Challenge is locked. Try again in about ${lockedMins} minute(s).`;
  }, [status, lockedMins]);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    form.post("/challenge", {
      onFinish: () => {
        // refresh status after a submit attempt
        loadStatus();
      },
    });
  }

  return (
    <div style={{ padding: 24, fontFamily: "system-ui", maxWidth: 520 }}>
      <Head title="Challenge" />

      <h1 style={{ fontSize: 20, marginBottom: 8 }}>Security Challenge</h1>

      <div style={{ marginBottom: 12, opacity: 0.85 }}>
        {loading && <div>Loading status…</div>}
        {!loading && status && (
          <div>
            <div>
              <strong>State:</strong> {status.state ?? "unknown"}
            </div>
            <div>
              <strong>Wrong attempts:</strong> {status.challenge_attempts}
            </div>
            {status.otp_expires_at && (
              <div>
                <strong>OTP expires at:</strong>{" "}
                {new Date(status.otp_expires_at).toLocaleString()}
              </div>
            )}
          </div>
        )}
      </div>

      {lockText && (
        <div
          style={{
            padding: 12,
            borderRadius: 8,
            background: "#fff3cd",
            border: "1px solid #ffeeba",
            marginBottom: 12,
          }}
        >
          {lockText}
        </div>
      )}

      <p style={{ marginBottom: 12 }}>
        Your login was flagged as risky. Enter the code to continue.
      </p>

      <form onSubmit={submit}>
        <input
          value={form.data.code}
          onChange={(e) => form.setData("code", e.target.value)}
          placeholder="Enter OTP code"
          disabled={isLocked}
          style={{
            padding: 10,
            width: 280,
            opacity: isLocked ? 0.6 : 1,
          }}
        />
        <button
          type="submit"
          disabled={isLocked || form.processing}
          style={{
            marginLeft: 10,
            padding: "10px 14px",
            opacity: isLocked || form.processing ? 0.6 : 1,
            cursor: isLocked || form.processing ? "not-allowed" : "pointer",
          }}
        >
          Verify
        </button>
      </form>

      {form.errors.code && (
        <div style={{ marginTop: 10, color: "crimson" }}>{form.errors.code}</div>
      )}

      {!form.errors.code && isLocked && (
        <div style={{ marginTop: 10, color: "#856404" }}>
          Verification is disabled while the challenge is locked.
        </div>
      )}
    </div>
  );
}