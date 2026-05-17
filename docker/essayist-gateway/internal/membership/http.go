package membership

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"
)

// HTTPChecker is the slow-path tier — calls the Symfony app's internal API.
//
// Wire format (matches src/Controller/Api/EssayistWriterPolicyController.php):
//
//	GET {template-with-{pubkey}-substituted}
//	Authorization: Bearer <PolicyToken>
//	-->
//	200 OK  {"approved": true|false, "reason": "..."}
//	401 Unauthorized on bad token
type HTTPChecker struct {
	urlTemplate string
	token       string
	client      *http.Client
}

func NewHTTPChecker(urlTemplate, token string, timeout time.Duration) *HTTPChecker {
	return &HTTPChecker{
		urlTemplate: urlTemplate,
		token:       token,
		client:      &http.Client{Timeout: timeout},
	}
}

type policyResponse struct {
	Approved bool   `json:"approved"`
	Reason   string `json:"reason,omitempty"`
}

// IsMember returns true on a positive policy response, false on a negative
// response, or a non-nil error on transport / parse failure.
func (h *HTTPChecker) IsMember(ctx context.Context, pubkeyHex string) (bool, error) {
	u := strings.ReplaceAll(h.urlTemplate, "{pubkey}", pubkeyHex)
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return false, fmt.Errorf("build policy request: %w", err)
	}
	req.Header.Set("Authorization", "Bearer "+h.token)
	req.Header.Set("Accept", "application/json")

	resp, err := h.client.Do(req)
	if err != nil {
		return false, fmt.Errorf("policy request: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return false, fmt.Errorf("policy endpoint returned status %d", resp.StatusCode)
	}

	var body policyResponse
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
		return false, fmt.Errorf("decode policy response: %w", err)
	}
	return body.Approved, nil
}

