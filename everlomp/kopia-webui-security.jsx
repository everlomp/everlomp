import axios from "axios";
import React, { useEffect, useState } from "react";
import { Alert, Button, Card, Col, Form, Row } from "react-bootstrap";

function errorText(error) {
  return error?.response?.data?.error || error?.message || "Request failed.";
}

export function EverlompWebUISecurity() {
  const [change, setChange] = useState({ new_password: "", confirm: "" });
  const [busy, setBusy] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState("");
  const [result, setResult] = useState("");

  const [twoFactor, setTwoFactor] = useState({
    loading: true,
    enabled: false,
    secret_present: false,
    needs_activation: false,
    secret_changed: false,
    secret_missing: false,
    secret_invalid: false,
    secret_error: "",
  });
  const [twoFactorBusy, setTwoFactorBusy] = useState(false);
  const [twoFactorError, setTwoFactorError] = useState("");
  const [twoFactorResult, setTwoFactorResult] = useState("");
  const [enrollment, setEnrollment] = useState(null);
  const [totpCode, setTotpCode] = useState("");

  useEffect(() => {
    refreshTwoFactorStatus();
  }, []);

  async function refreshTwoFactorStatus() {
    try {
      const response = await axios.get("everlomp-api/two-factor/status");
      setTwoFactor({ loading: false, ...(response.data.two_factor || {}) });
    } catch (requestError) {
      setTwoFactor((old) => ({ ...old, loading: false }));
      setTwoFactorError(errorText(requestError));
    }
  }

  function generatePassword() {
    setError("");
    setResult("");
    if (!window.crypto?.getRandomValues) {
      setError("Secure password generation is not available in this browser.");
      return;
    }

    const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%_-+=?";
    const bytes = new Uint8Array(28);
    window.crypto.getRandomValues(bytes);
    let password = "";
    for (const value of bytes) password += alphabet[value % alphabet.length];

    setChange({ new_password: password, confirm: password });
    setShowPassword(true);
    setResult("Generated a new WebUI password. Copy it somewhere safe before applying it.");
  }

  async function changeWebUIPassword(event) {
    event.preventDefault();
    setError("");
    setResult("");

    const next = change.new_password;
    if (next.length < 12) {
      setError("Use a Kopia WebUI password with at least 12 characters.");
      return;
    }
    if (!/^[\x20-\x7E]+$/.test(next)) {
      setError("Use printable ASCII characters for the Kopia WebUI password.");
      return;
    }
    if (next !== change.confirm) {
      setError("The new WebUI passwords do not match.");
      return;
    }
    if (!window.confirm("Change the Kopia WebUI login password now? You will need to sign in again with the new password.")) return;

    setBusy(true);
    try {
      const response = await axios.post("everlomp-api/webui-password/change", { new_password: next });
      setChange({ new_password: "", confirm: "" });
      setShowPassword(false);
      setResult(response.data.webui?.detail || "Kopia WebUI password changed. Sign in again with the new password.");

      // The Kopia server is restarted so it can pick up KOPIA_SERVER_PASSWORD.
      // That invalidates the old Basic-Auth credentials and Kopia CSRF token.
      // Give the success message a moment to render, then force a full request;
      // the browser will authenticate against the new Kopia process.
      window.setTimeout(() => {
        const reloadUrl = new URL(window.location.href);
        reloadUrl.searchParams.set("_everlomp_webui_password", Date.now().toString());
        window.location.replace(reloadUrl.toString());
      }, 900);
    } catch (requestError) {
      setError(errorText(requestError));
      setBusy(false);
    }
  }

  async function generateTwoFactor() {
    setTwoFactorBusy(true);
    setTwoFactorError("");
    setTwoFactorResult("");
    setTotpCode("");
    try {
      const response = await axios.post("everlomp-api/two-factor/generate", {});
      setEnrollment(response.data.two_factor || null);
      setTwoFactorResult("Setup generated. Important: 2FA is NOT enabled yet. Scan the QR, put the exact value into Docker secret 2fa, redeploy/restart, then return to this page and verify a 6-digit code.");
    } catch (requestError) {
      setTwoFactorError(errorText(requestError));
    } finally {
      setTwoFactorBusy(false);
    }
  }

  async function copyTwoFactorSecret() {
    if (!enrollment?.secret) return;
    try {
      await navigator.clipboard.writeText(enrollment.secret);
      setTwoFactorResult("Copied the TOTP value. Store it as Docker secret 2fa, then redeploy this container.");
    } catch {
      setTwoFactorError("Could not copy automatically. Select the secret and copy it manually.");
    }
  }

  async function activateTwoFactor(event) {
    event.preventDefault();
    setTwoFactorError("");
    setTwoFactorResult("");
    if (!/^\d{6}$/.test(totpCode)) {
      setTwoFactorError("Enter the current 6-digit authenticator code.");
      return;
    }
    setTwoFactorBusy(true);
    try {
      const response = await axios.post("everlomp-api/two-factor/activate", { code: totpCode });
      setTwoFactor({ loading: false, ...(response.data.two_factor || {}) });
      setEnrollment(null);
      setTotpCode("");
      setTwoFactorResult(response.data.detail || "Two-factor authentication is enabled for Kopia.");
    } catch (requestError) {
      setTwoFactorError(errorText(requestError));
    } finally {
      setTwoFactorBusy(false);
    }
  }

  async function disableTwoFactor() {
    if (!window.confirm("Disable two-factor authentication for Kopia? Your Docker secret named 2fa will not be deleted.")) return;
    setTwoFactorBusy(true);
    setTwoFactorError("");
    setTwoFactorResult("");
    try {
      const response = await axios.post("everlomp-api/two-factor/disable", {});
      setTwoFactor({ loading: false, ...(response.data.two_factor || {}) });
      setTotpCode("");
      setTwoFactorResult(response.data.detail || "Two-factor authentication is disabled.");
    } catch (requestError) {
      setTwoFactorError(errorText(requestError));
    } finally {
      setTwoFactorBusy(false);
    }
  }

  return (
    <>
      <Card className="mt-3 mb-3">
        <Card.Body>
          <Card.Title className="h5">Kopia WebUI Password</Card.Title>
          <Card.Text className="text-muted">
            Change the password used to sign in to this Kopia WebUI. This is separate from the repository encryption password. The WebUI username remains <code>admin</code>.
          </Card.Text>

          {error && <Alert variant="danger" className="py-2">{error}</Alert>}
          {result && <Alert variant="success" className="py-2">{result}</Alert>}

          <Form onSubmit={changeWebUIPassword}>
            <Row className="g-2 align-items-end">
              <Col md={5}>
                <Form.Label>New WebUI password</Form.Label>
                <Form.Control
                  type={showPassword ? "text" : "password"}
                  minLength={12}
                  value={change.new_password}
                  onChange={(event) => setChange((old) => ({ ...old, new_password: event.target.value }))}
                  autoComplete="new-password"
                  disabled={busy}
                  required
                />
              </Col>
              <Col md={5}>
                <Form.Label>Confirm new password</Form.Label>
                <Form.Control
                  type={showPassword ? "text" : "password"}
                  minLength={12}
                  value={change.confirm}
                  onChange={(event) => setChange((old) => ({ ...old, confirm: event.target.value }))}
                  autoComplete="new-password"
                  disabled={busy}
                  required
                />
              </Col>
              <Col md={2} className="d-grid gap-2">
                <Button type="button" variant="secondary" onClick={generatePassword} disabled={busy}>Generate</Button>
                <Button
                  type="button"
                  variant="outline-secondary"
                  onClick={() => setShowPassword((visible) => !visible)}
                  disabled={busy || !change.new_password}
                >
                  {showPassword ? "Hide" : "Show"}
                </Button>
                <Button type="submit" variant="primary" disabled={busy}>
                  {busy ? "Changing…" : "Change Password"}
                </Button>
              </Col>
            </Row>
            <Form.Text>
              Everlomp stores this credential in its protected secret store. Changing it restarts only the Kopia service; your repository encryption password is not changed.
            </Form.Text>
          </Form>
        </Card.Body>
      </Card>

      <Card className="mt-3 mb-3">
        <Card.Body>
          <Card.Title className="h5">Two-Factor Authentication</Card.Title>
          <Card.Text className="text-muted">
            {"Add a standard 6-digit TOTP code after the Kopia password. Google Authenticator, WinAuth, Aegis and other RFC 6238 authenticator apps are supported. The TOTP key itself must be supplied to this container as Docker secret 2fa."}
          </Card.Text>

          {twoFactorError && <Alert variant="danger" className="py-2">{twoFactorError}</Alert>}
          {twoFactorResult && <Alert variant="success" className="py-2">{twoFactorResult}</Alert>}

          {twoFactor.loading ? (
            <p className="text-muted mb-0">Checking 2FA status…</p>
          ) : twoFactor.enabled ? (
            <>
              <Alert variant="success" className="py-2">
                <strong>2FA is enabled.</strong> Kopia now requires the WebUI password and a valid authenticator session.
              </Alert>
              <div className="d-flex flex-wrap gap-2">
                <Button variant="danger" onClick={disableTwoFactor} disabled={twoFactorBusy}>
                  {twoFactorBusy ? "Disabling…" : "Disable 2FA"}
                </Button>
                <Button variant="outline-secondary" onClick={generateTwoFactor} disabled={twoFactorBusy}>
                  Generate Replacement Secret
                </Button>
              </div>
              <Form.Text className="d-block mt-2">
                Disabling 2FA does not remove Docker secret <code>2fa</code>. A replacement secret is not used until you attach it to the container and verify a code from it.
              </Form.Text>
            </>
          ) : (
            <>
              {twoFactor.secret_invalid ? (
                <Alert variant="danger" className="py-2">
                  <strong>Docker secret <code>2fa</code> is mounted but invalid.</strong>{" "}
                  {twoFactor.secret_error || "It is not a valid Base32 TOTP secret."} Generate a replacement setup below, then replace Docker secret <code>2fa</code> with the generated Base32 value and redeploy.
                </Alert>
              ) : twoFactor.secret_present ? (
                <Alert variant={twoFactor.secret_changed ? "warning" : "info"} className="py-2">
                  <strong>Docker secret <code>2fa</code> is detected, but 2FA is NOT active yet.</strong>{" "}
                  {twoFactor.secret_changed
                    ? "It differs from the previously activated key. Enter a current 6-digit code below and click Verify & Enable 2FA."
                    : "Enter a current 6-digit code below and click Verify & Enable 2FA to finish activation."}
                </Alert>
              ) : twoFactor.secret_missing ? (
                <Alert variant="warning" className="py-2">
                  A 2FA key was previously activated, but Docker secret <code>2fa</code> is not currently mounted. 2FA is not enforced until the secret is restored and verified.
                </Alert>
              ) : (
                <Alert variant="secondary" className="py-2">
                  <strong>2FA is disabled.</strong> Generate a setup key, scan it in your authenticator app, then add it to Docker as secret <code>2fa</code>.
                </Alert>
              )}

              {twoFactor.secret_present && !twoFactor.secret_invalid && (
                <Form onSubmit={activateTwoFactor} className="mb-3">
                  <Row className="g-2 align-items-end">
                    <Col md={7}>
                      <Form.Label>Authenticator code</Form.Label>
                      <Form.Control
                        type="text"
                        inputMode="numeric"
                        pattern="[0-9]{6}"
                        minLength={6}
                        maxLength={6}
                        value={totpCode}
                        onChange={(event) => setTotpCode(event.target.value.replace(/\D/g, "").slice(0, 6))}
                        autoComplete="one-time-code"
                        placeholder="123456"
                        disabled={twoFactorBusy}
                        required
                      />
                    </Col>
                    <Col md={5} className="d-grid">
                      <Button type="submit" variant="primary" disabled={twoFactorBusy || totpCode.length !== 6}>
                        {twoFactorBusy ? "Verifying…" : "Verify & Enable 2FA"}
                      </Button>
                    </Col>
                  </Row>
                </Form>
              )}

              <Button variant="secondary" onClick={generateTwoFactor} disabled={twoFactorBusy}>
                {twoFactorBusy ? "Generating…" : twoFactor.secret_present ? "Generate Replacement Secret" : "Enable 2FA / Generate Setup"}
              </Button>
            </>
          )}

          {enrollment && (
            <div className="border rounded p-3 mt-3">
              <h6>Authenticator setup</h6>
              <p className="mb-2">
                <strong>1.</strong> Scan this QR code in Google Authenticator, WinAuth, Aegis, or another standard TOTP app.
              </p>
              <div className="mb-3" style={{ maxWidth: "280px" }}>
                <img
                  src={`data:image/png;base64,${enrollment.qr_png}`}
                  alt="Kopia 2FA QR code"
                  className="img-fluid bg-white p-2 rounded"
                />
              </div>
              <p className="mb-2"><strong>Manual secret</strong></p>
              <Row className="g-2 mb-3">
                <Col md={9}>
                  <Form.Control type="text" readOnly value={enrollment.secret || ""} className="font-monospace" />
                </Col>
                <Col md={3} className="d-grid">
                  <Button variant="outline-secondary" onClick={copyTwoFactorSecret}>Copy</Button>
                </Col>
              </Row>
              <Alert variant="warning" className="py-2 mb-3">
                <strong>Important: generating and mounting the secret does NOT enable 2FA.</strong> You must come back to this page after the container restarts and verify one live 6-digit code before protection is activated.
              </Alert>
              <p className="mb-2">
                <strong>2.</strong> Create or update Docker secret <code>2fa</code> with the exact Base32 value above and attach it to this container.
              </p>
              <p className="mb-2">
                <strong>3.</strong> Redeploy/restart the container so the new Docker secret appears at <code>/run/secrets/2fa</code>.
              </p>
              <Alert variant="info" className="py-2 mb-2">
                <strong>4. Final activation step:</strong> come back to <strong>Kopia → Preferences → Two-Factor Authentication</strong>, enter the current 6-digit code from your authenticator, and click <strong>Verify & Enable 2FA</strong>.
              </Alert>
              <Form.Text>
                Until step 4 succeeds, 2FA remains disabled. This generated setup is not activated or persisted by Everlomp; the long-term TOTP key comes only from Docker secret <code>2fa</code>. Parameters: SHA-1, 6 digits, 30-second period.
              </Form.Text>
            </div>
          )}
        </Card.Body>
      </Card>
    </>
  );
}
