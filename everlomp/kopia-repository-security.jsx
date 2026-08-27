import axios from "axios";
import React, { useCallback, useEffect, useState } from "react";
import { Alert, Button, Card, Col, Form, Row, Spinner } from "react-bootstrap";

function errorText(error) {
  return error?.response?.data?.error || error?.message || "Request failed.";
}

export function EverlompRepositorySecurity() {
  const [source, setSource] = useState({ connected: false, config_present: false, password_configured: false, detail: "Loading…" });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [currentPassword, setCurrentPassword] = useState("");
  const [currentPasswordBusy, setCurrentPasswordBusy] = useState(false);
  const [change, setChange] = useState({ new_password: "", confirm: "" });
  const [changeBusy, setChangeBusy] = useState(false);
  const [showChangePassword, setShowChangePassword] = useState(false);
  const [result, setResult] = useState("");

  const refresh = useCallback(async () => {
    try {
      const response = await axios.get("everlomp-api/status");
      setSource(response.data.source || {});
      setError("");
    } catch (requestError) {
      setError(errorText(requestError));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  async function saveCurrentPassword(event) {
    event.preventDefault();
    setCurrentPasswordBusy(true);
    setError("");
    setResult("");
    try {
      const response = await axios.post("everlomp-api/source-password", { password: currentPassword });
      setSource(response.data.source || {});
      setCurrentPassword("");
      setResult("Repository password verified and saved in Everlomp's protected secret store.");
      await refresh();
    } catch (requestError) {
      setError(errorText(requestError));
    } finally {
      setCurrentPasswordBusy(false);
    }
  }

  function generatePassword() {
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
    setShowChangePassword(true);
    setResult("Generated a new password. It is shown below so you can copy it. Save it somewhere outside this server before applying it.");
  }

  async function changeRepositoryPassword(event) {
    event.preventDefault();
    setError("");
    setResult("");

    const next = change.new_password;
    if (next.length < 12) {
      setError("Use a repository password with at least 12 characters.");
      return;
    }
    if (next !== change.confirm) {
      setError("The new repository passwords do not match.");
      return;
    }
    if (!window.confirm("Change the Kopia repository encryption password now? Save the new password outside this server before continuing.")) return;

    setChangeBusy(true);
    try {
      const response = await axios.post("everlomp-api/source-password/change", { new_password: next });
      setSource(response.data.source || {});
      setChange({ new_password: "", confirm: "" });
      setShowChangePassword(false);
      setResult(response.data.source?.detail || "Repository password changed. Reloading the Kopia WebUI with a fresh session…");

      // Changing the repository password restarts the Kopia server. Kopia's
      // CSRF token is process-local, so the currently loaded HTMLUI still has
      // the token from the old server process and subsequent API calls receive
      // 403 Forbidden. Force a full navigation after the privileged helper has
      // confirmed that the new Kopia process is listening again.
      const reloadUrl = new URL(window.location.href);
      reloadUrl.searchParams.set("_everlomp_kopia_restart", Date.now().toString());
      window.location.replace(reloadUrl.toString());
      return;
    } catch (requestError) {
      setError(errorText(requestError));
    } finally {
      setChangeBusy(false);
    }
  }

  return (
    <Card className="mt-3 mb-3">
      <Card.Body>
        <Card.Title className="h5">Repository Password</Card.Title>
        <Card.Text className="text-muted">
          Manage the encryption password for this primary Kopia repository. Everlomp keeps the password in its protected secret store so Kopia can reopen the repository after service or container restarts.
        </Card.Text>

        {loading ? (
          <div className="d-flex align-items-center gap-2 text-muted">
            <Spinner animation="border" size="sm" /> Checking Everlomp repository access…
          </div>
        ) : (
          <>
            {error && <Alert variant="danger" className="py-2">{error}</Alert>}
            {result && <Alert variant="success" className="py-2">{result}</Alert>}

            {source.connected ? (
              <Alert variant="success" className="py-2">
                Everlomp can open this repository using the protected repository password.
              </Alert>
            ) : (
              <Alert variant="warning">
                <div className="mb-2"><strong>Everlomp cannot currently reopen this repository.</strong></div>
                <div className="mb-3">{source.detail || "Enter the current repository encryption password so service restarts and scheduled replication can open it."}</div>
                <Form onSubmit={saveCurrentPassword}>
                  <Row className="g-2 align-items-end">
                    <Col md={7} lg={5}>
                      <Form.Label>Current repository password</Form.Label>
                      <Form.Control
                        type="password"
                        value={currentPassword}
                        onChange={(event) => setCurrentPassword(event.target.value)}
                        autoComplete="current-password"
                        required
                      />
                    </Col>
                    <Col xs="auto">
                      <Button type="submit" disabled={currentPasswordBusy}>
                        {currentPasswordBusy ? "Checking…" : "Save & Verify"}
                      </Button>
                    </Col>
                  </Row>
                </Form>
              </Alert>
            )}

            {source.connected && (
              <>
                <hr />
                <h6>Change repository encryption password</h6>
                <p className="text-muted">
                  Everlomp briefly stops Kopia, changes the repository password through Kopia&apos;s interactive prompt, updates the protected Everlomp secret only after Kopia succeeds, then starts Kopia again and verifies the repository reopens.
                </p>
                <Alert variant="warning" className="py-2">
                  <strong>Save the new password somewhere outside this server before changing it.</strong> A disconnected Kopia repository does not have a password-reset mechanism.
                </Alert>
                <Form onSubmit={changeRepositoryPassword}>
                  <Row className="g-2 align-items-end">
                    <Col md={5}>
                      <Form.Label>New repository password</Form.Label>
                      <Form.Control
                        type={showChangePassword ? "text" : "password"}
                        minLength={12}
                        value={change.new_password}
                        onChange={(event) => setChange((old) => ({ ...old, new_password: event.target.value }))}
                        autoComplete="new-password"
                        required
                      />
                    </Col>
                    <Col md={5}>
                      <Form.Label>Confirm new password</Form.Label>
                      <Form.Control
                        type={showChangePassword ? "text" : "password"}
                        minLength={12}
                        value={change.confirm}
                        onChange={(event) => setChange((old) => ({ ...old, confirm: event.target.value }))}
                        autoComplete="new-password"
                        required
                      />
                    </Col>
                    <Col md={2} className="d-grid gap-2">
                      <Button type="button" variant="secondary" onClick={generatePassword} disabled={changeBusy}>Generate</Button>
                      <Button
                        type="button"
                        variant="outline-secondary"
                        onClick={() => setShowChangePassword((visible) => !visible)}
                        disabled={changeBusy || !change.new_password}
                      >
                        {showChangePassword ? "Hide" : "Show"}
                      </Button>
                      <Button type="submit" variant="danger" disabled={changeBusy}>{changeBusy ? "Changing…" : "Change Password"}</Button>
                    </Col>
                  </Row>
                  <Form.Text>The new password is sent to Kopia through its interactive prompt, not as a command-line argument.</Form.Text>
                </Form>
              </>
            )}
          </>
        )}
      </Card.Body>
    </Card>
  );
}
