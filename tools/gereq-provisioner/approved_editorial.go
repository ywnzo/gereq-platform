package main

import (
	"crypto/sha256"
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"html"
	"strings"
)

//go:embed examples/editorial/approved-baseline.json
var approvedEditorial []byte

// compileApprovedEditorial keeps the user-edited native layout while replacing only content and instance-specific references.
func compileApprovedEditorial(b Brief, s FunnelSpec, id string) (FunnelArtifact, error) {
	if b.IllustrationURL == "" {
		return FunnelArtifact{}, errors.New("editorial layout requires an illustration uploaded to this customer instance")
	}
	var source struct {
		Data struct {
			Blocks []NativeBlock `json:"blocks"`
		} `json:"data"`
	}
	if json.Unmarshal(approvedEditorial, &source) != nil || len(source.Data.Blocks) == 0 {
		return FunnelArtifact{}, errors.New("invalid approved editorial baseline")
	}
	rich := func(v string) string { return strings.ReplaceAll(html.EscapeString(v), "\n", "<br>") }
	hero := rich(s.Headline)
	if p := strings.SplitN(s.Headline, ". ", 2); len(p) == 2 {
		hero = html.EscapeString(p[0]+".") + "<br><em>" + html.EscapeString(p[1]) + "</em>"
	}
	replacements := map[string]string{
		"STRATEGICKÉ KONZULTACE · STUDIO JASNO": strings.ToUpper(rich(s.Eyebrow)),
		"Velké věci.<br><em>Jasný směr.</em>":   hero,
		"Máte nápad. Nebo spoustu otázek.<br>Společně najdeme to podstatné a proměníme ho v další krok.": rich(s.Intro),
		"Jasno v tom, na čem záleží.":                                              rich(s.BenefitsTitle),
		"Dobrý rozhovor.<br>Nová perspektiva.":                                     rich(s.ProcessTitle),
		"Co vám právě<br>leží v hlavě?":                                            rich(s.ContactTitle),
		"Napište nám pár vět o svém záměru. Konzultace mohou proběhnout i online.": rich(s.ContactBody),
	}
	originalBenefits := []CopyItem{{"Jasné priority.", "Ujasníte si, co je pro váš záměr opravdu důležité."}, {"Nová perspektiva.", "Uvidíte souvislosti a možnosti, které vám mohly unikat."}, {"Vlastní směr.", "Získáte pevnější podklady pro vlastní rozhodnutí."}}
	originalProcess := []CopyItem{{"Začneme u vás.", "Přiblížíte nám svou situaci, projekt a to, co právě řešíte."}, {"Podíváme se jinak.", "Probereme souvislosti a možnosti, které dávají smysl vašemu záměru."}, {"Najdeme další krok.", "Shrneme podstatné a společně pojmenujeme další směr."}}
	for i := 0; i < 3; i++ {
		replacements[originalBenefits[i].Title] = rich(s.Benefits[i].Title)
		replacements[originalBenefits[i].Body] = rich(s.Benefits[i].Body)
		replacements[originalProcess[i].Title] = rich(s.Process[i].Title)
		replacements[originalProcess[i].Body] = rich(s.Process[i].Body)
	}
	if b.Language == "en" {
		replacements["CO SI ODNESETE"] = "WHAT YOU GAIN"
		replacements["02 / JAK TO PROBÍHÁ"] = "HOW IT WORKS"
		replacements["04 / TEĎ JE ŘADA NA VÁS"] = "START A CONVERSATION"
	}
	n := 0
	var formID string
	var ctas []*NativeBlock
	var transform func([]NativeBlock) ([]NativeBlock, error)
	transform = func(blocks []NativeBlock) ([]NativeBlock, error) {
		for i := range blocks {
			v := &blocks[i]
			n++
			sum := sha256.Sum256([]byte(fmt.Sprintf("%s:%d", id, n)))
			sum[6] = (sum[6] & 15) | 64
			sum[8] = (sum[8] & 63) | 128
			v.ID = fmt.Sprintf("%x-%x-%x-%x-%x", sum[:4], sum[4:6], sum[6:8], sum[8:10], sum[10:16])
			v.ClientID = fmt.Sprintf("approved-%03d", n)
			for _, k := range []string{"text", "body", "label"} {
				if old, ok := v.Payload[k].(string); ok {
					if value, found := replacements[old]; found {
						v.Payload[k] = value
					}
				}
			}
			if v.Type == "form_field" && b.Language == "en" {
				labels := map[string]string{"name": "Your name", "email": "Email address", "details": "What would you like to discuss?"}
				if name, ok := v.Payload["name"].(string); ok {
					if label, found := labels[name]; found {
						v.Payload["label"] = label
					}
				}
			}
			if v.Type == "image" {
				v.Payload["url"] = b.IllustrationURL
				v.Payload["media_id"] = strings.TrimSuffix(strings.TrimPrefix(b.IllustrationURL, "/media/"), "/file")
			}
			if v.Type == "cta" {
				ctas = append(ctas, v)
			}
			if v.Type == "form" {
				formID = v.ID
				v.Payload["submit_label"] = s.CTA
				if v.Payload["submit_text_color"] == "#ffffff" {
					v.Payload["submit_text_color"] = "light-background"
				}
				if b.Goal == "booking" {
					v.Type = "cta"
					v.Payload = map[string]any{"label": s.CTA, "url": b.BookingURL, "style": "primary", "bg_color": "dark-background", "text_color": "light-background", "padding": 18, "margin": 0}
				}
			}
			if raw, ok := v.Payload["children"]; ok {
				encoded, _ := json.Marshal(raw)
				var children []NativeBlock
				if json.Unmarshal(encoded, &children) != nil {
					return nil, errors.New("invalid approved blocks")
				}
				var err error
				children, err = transform(children)
				if err != nil {
					return nil, err
				}
				v.Payload["children"] = children
			}
		}
		return blocks, nil
	}
	blocks, err := transform(source.Data.Blocks)
	if err != nil {
		return FunnelArtifact{}, err
	}
	if formID == "" || len(ctas) == 0 {
		return FunnelArtifact{}, errors.New("approved baseline missing form or CTA")
	}
	for _, v := range ctas {
		if b.Goal == "booking" {
			v.Payload["url"] = b.BookingURL
			v.Payload["label"] = s.CTA
		} else {
			v.Payload["url"] = "#cms-field-" + formID + "-name"
			if b.Language == "en" {
				v.Payload["label"] = "Get in touch ↗"
			}
		}
	}
	return FunnelArtifact{1, id, b.Name + " — " + s.Headline, b.Slug, blocks}, nil
}
