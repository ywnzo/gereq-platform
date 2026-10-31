package main

import (
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"html"
	"regexp"
	"strings"
)

// NativeTemplate contains CMS builder blocks, with named copy placeholders.
type NativeTemplate struct {
	Blocks []NativeBlock `json:"blocks"`
}

var templateSlotPattern = regexp.MustCompile(`\{\{([a-z0-9_]+)\}\}`)
var templateAllowedSlots = map[string]bool{"name": true, "offer": true, "audience": true, "eyebrow": true, "headline": true, "intro": true, "cta": true, "benefits_title": true, "process_title": true, "faq_title": true, "contact_title": true, "contact_body": true, "benefit_1_title": true, "benefit_1_body": true, "benefit_2_title": true, "benefit_2_body": true, "benefit_3_title": true, "benefit_3_body": true, "process_1_title": true, "process_1_body": true, "process_2_title": true, "process_2_body": true, "process_3_title": true, "process_3_body": true, "faq_1_title": true, "faq_1_body": true, "faq_2_title": true, "faq_2_body": true, "faq_3_title": true, "faq_3_body": true}
var templateMarkup = regexp.MustCompile(`(?i)</?em>|<br\s*/?>`)
var templateUnsafeKey = regexp.MustCompile(`(?i)css|script|^html$|^gereq_|^bindings$|^data_|^on[a-z]`)
var templateColors = map[string]bool{"none": true, "inherit": true, "paper": true, "background": true, "surface": true, "header": true, "ink": true, "muted": true, "brand": true, "secondary": true, "accent": true, "brand-soft": true, "light-background": true, "light-surface": true, "light-header": true, "dark-background": true, "dark-surface": true, "dark-header": true}
var templateColorKeys = map[string]bool{"bg_color": true, "text_color": true, "border_color": true, "hover_color": true, "input_bg_color": true, "input_text_color": true, "input_border_color": true, "input_focus_color": true, "submit_bg_color": true, "submit_text_color": true}

func nativeTemplateChildren(payload map[string]any) ([]NativeBlock, error) {
	v, ok := payload["children"]
	if !ok {
		return nil, nil
	}
	raw, err := json.Marshal(v)
	if err != nil {
		return nil, err
	}
	var children []NativeBlock
	if json.Unmarshal(raw, &children) != nil {
		return nil, errors.New("invalid native template children")
	}
	return children, nil
}
func checkTemplateValue(v any, depth int) error {
	if depth > 12 {
		return errors.New("native template property too deep")
	}
	switch x := v.(type) {
	case map[string]any:
		for k, value := range x {
			if templateUnsafeKey.MatchString(k) {
				return errors.New("unsupported nested template property")
			}
			if err := checkTemplateValue(value, depth+1); err != nil {
				return err
			}
		}
	case []any:
		for _, value := range x {
			if err := checkTemplateValue(value, depth+1); err != nil {
				return err
			}
		}
	case string:
		if strings.Contains(strings.ToLower(x), "<script") || strings.HasPrefix(strings.ToLower(strings.TrimSpace(x)), "javascript:") {
			return errors.New("unsupported template script")
		}
	}
	return nil
}
func templateRequired(v any) bool {
	switch x := v.(type) {
	case bool:
		return x
	case float64:
		return x == 1
	case int:
		return x == 1
	case string:
		return x == "1" || x == "true"
	}
	return false
}
func (t NativeTemplate) validate() error {
	if len(t.Blocks) == 0 {
		return errors.New("native template has no blocks")
	}
	count, forms, ctas, h1 := 0, 0, 0, 0
	slots := map[string]bool{}
	var walk func([]NativeBlock, int, bool) error
	walk = func(blocks []NativeBlock, depth int, inForm bool) error {
		if depth > 8 {
			return errors.New("native template too deep")
		}
		for _, b := range blocks {
			count++
			if count > 300 || b.Payload == nil || !contains([]string{"layout", "heading", "text", "cta", "image", "divider", "form", "form_field", "icon", "spacer"}, b.Type) {
				return errors.New("unsupported native template block")
			}
			for key, value := range b.Payload {
				if templateUnsafeKey.MatchString(key) {
					return errors.New("unsupported native template property")
				}
				if templateColorKeys[key] {
					s, ok := value.(string)
					if !ok || !templateColors[s] {
						return errors.New("native template must use color variables")
					}
				}
				if key != "children" {
					if err := checkTemplateValue(value, 0); err != nil {
						return err
					}
				}
				if key == "body" || key == "text" || key == "label" || key == "submit_label" {
					s, ok := value.(string)
					if !ok || strings.Contains(templateMarkup.ReplaceAllString(s, ""), "<") {
						return errors.New("unsupported native template markup")
					}
					remaining := templateSlotPattern.ReplaceAllString(s, "")
					if strings.Contains(remaining, "{{") || strings.Contains(remaining, "}}") {
						return errors.New("invalid native template slot syntax")
					}
					for _, m := range templateSlotPattern.FindAllStringSubmatch(s, -1) {
						if !templateAllowedSlots[m[1]] {
							return errors.New("unknown native template slot")
						}
						if (m[1] == "headline" && b.Type == "heading" && key == "text") || (m[1] == "intro" && b.Type == "text" && key == "body") || (m[1] == "cta" && b.Type == "cta" && key == "label") {
							slots[m[1]] = true
						}
					}
				}
			}
			if b.Type == "heading" {
				switch b.Payload["level"] {
				case 1, float64(1):
					h1++
				}
			}
			if b.Type == "cta" {
				ctas++
			}
			if b.Type == "image" && b.Payload["url"] != "{{image_url}}" {
				return errors.New("template images must use the customer image placeholder")
			}
			children, err := nativeTemplateChildren(b.Payload)
			if err != nil {
				return err
			}
			if b.Type == "form" {
				forms++
				if inForm || b.Payload["action"] != "site_inquiries.create" {
					return errors.New("unsupported native template form")
				}
				fields := map[string]string{}
				for _, child := range children {
					if child.Type != "form_field" {
						return errors.New("form must contain form fields only")
					}
					name, _ := child.Payload["name"].(string)
					control, _ := child.Payload["control"].(string)
					if _, exists := fields[name]; exists {
						return errors.New("duplicate form field")
					}
					if !contains([]string{"name", "email", "phone", "details"}, name) {
						return errors.New("unsupported inquiry form field")
					}
					fields[name] = control
					if !templateRequired(child.Payload["required"]) {
						return errors.New("inquiry fields must be required")
					}
				}
				if fields["name"] != "text" || fields["email"] != "email" || fields["details"] != "textarea" || (fields["phone"] != "" && fields["phone"] != "tel") || len(fields) > 4 {
					return errors.New("invalid inquiry form fields")
				}
			}
			if b.Type == "form_field" && !inForm {
				return errors.New("form field outside form")
			}
			if len(children) > 0 && b.Type != "layout" && b.Type != "form" {
				return errors.New("unsupported native block children")
			}
			if err = walk(children, depth+1, inForm || b.Type == "form"); err != nil {
				return err
			}
		}
		return nil
	}
	if err := walk(t.Blocks, 0, false); err != nil {
		return err
	}
	if forms != 1 || ctas < 1 || h1 != 1 || !slots["headline"] || !slots["intro"] || !slots["cta"] {
		return errors.New("template requires one headline, one inquiry form, a CTA and headline/intro/cta slots")
	}
	return nil
}
func templateHasImages(blocks []NativeBlock) bool {
	for _, b := range blocks {
		if b.Type == "image" {
			return true
		}
		children, _ := nativeTemplateChildren(b.Payload)
		if templateHasImages(children) {
			return true
		}
	}
	return false
}
func compileNativeTemplate(b Brief, s FunnelSpec, t NativeTemplate, id string) (FunnelArtifact, error) {
	if err := b.validate(); err != nil {
		return FunnelArtifact{}, err
	}
	if err := t.validate(); err != nil {
		return FunnelArtifact{}, err
	}
	if err := s.validate(); err != nil {
		return FunnelArtifact{}, err
	}
	if s.Design != nil {
		if err := s.Design.validatePalette(); err != nil {
			return FunnelArtifact{}, err
		}
	}
	if templateHasImages(t.Blocks) && b.IllustrationURL == "" {
		return FunnelArtifact{}, errors.New("native template requires a customer image")
	}
	slots := map[string]string{"name": b.Name, "offer": b.Offer, "audience": b.Audience, "eyebrow": s.Eyebrow, "headline": s.Headline, "intro": s.Intro, "cta": s.CTA, "benefits_title": s.BenefitsTitle, "process_title": s.ProcessTitle, "faq_title": s.FAQTitle, "contact_title": s.ContactTitle, "contact_body": s.ContactBody}
	for i := 0; i < 3; i++ {
		n := i + 1
		slots[fmt.Sprintf("benefit_%d_title", n)] = s.Benefits[i].Title
		slots[fmt.Sprintf("benefit_%d_body", n)] = s.Benefits[i].Body
		slots[fmt.Sprintf("process_%d_title", n)] = s.Process[i].Title
		slots[fmt.Sprintf("process_%d_body", n)] = s.Process[i].Body
		slots[fmt.Sprintf("faq_%d_title", n)] = s.FAQ[i].Title
		slots[fmt.Sprintf("faq_%d_body", n)] = s.FAQ[i].Body
	}
	raw, err := json.Marshal(t.Blocks)
	if err != nil {
		return FunnelArtifact{}, err
	}
	var blocks []NativeBlock
	if err = json.Unmarshal(raw, &blocks); err != nil {
		return FunnelArtifact{}, err
	}
	count := 0
	formID := ""
	ctas := []*NativeBlock{}
	var walk func([]NativeBlock) ([]NativeBlock, error)
	walk = func(nodes []NativeBlock) ([]NativeBlock, error) {
		for i := range nodes {
			v := &nodes[i]
			count++
			h := sha256.Sum256([]byte(fmt.Sprintf("%s:%d", id, count)))
			h[6] = (h[6] & 15) | 64
			h[8] = (h[8] & 63) | 128
			v.ID = fmt.Sprintf("%x-%x-%x-%x-%x", h[:4], h[4:6], h[6:8], h[8:10], h[10:16])
			v.ClientID = fmt.Sprintf("template-%03d", count)
			for _, key := range []string{"text", "body", "label", "submit_label"} {
				if val, ok := v.Payload[key].(string); ok {
					v.Payload[key] = templateSlotPattern.ReplaceAllStringFunc(val, func(x string) string {
						m := templateSlotPattern.FindStringSubmatch(x)
						return strings.ReplaceAll(html.EscapeString(slots[m[1]]), "\n", "<br>")
					})
				}
			}
			if v.Type == "image" {
				v.Payload["url"] = b.IllustrationURL
				v.Payload["media_id"] = strings.TrimSuffix(strings.TrimPrefix(b.IllustrationURL, "/media/"), "/file")
			}
			if v.Type == "cta" {
				ctas = append(ctas, v)
			}
			children, err := nativeTemplateChildren(v.Payload)
			if err != nil {
				return nil, err
			}
			if v.Type == "form" {
				formID = v.ID
				if b.Goal == "booking" {
					v.Type = "cta"
					v.Payload = map[string]any{"label": s.CTA, "url": b.BookingURL, "style": "primary", "bg_color": "brand", "text_color": "dark-background"}
					children = nil
				} else {
					v.Payload["submit_label"] = s.CTA
					found := false
					for _, x := range children {
						if x.Payload["name"] == "phone" {
							found = true
						}
					}
					if !found {
						label := "Telefon"
						if b.Language == "en" {
							label = "Phone"
						}
						children = append(children, NativeBlock{Type: "form_field", Payload: map[string]any{"name": "phone", "control": "tel", "label": label, "required": 1, "full": 1}})
					}
				}
			}
			if len(children) > 0 {
				children, err = walk(children)
				if err != nil {
					return nil, err
				}
				v.Payload["children"] = children
			}
		}
		return nodes, nil
	}
	blocks, err = walk(blocks)
	if err != nil {
		return FunnelArtifact{}, err
	}
	for _, v := range ctas {
		if b.Goal == "booking" {
			v.Payload["url"] = b.BookingURL
		} else {
			v.Payload["url"] = "#cms-field-" + formID + "-name"
		}
	}
	artifact := FunnelArtifact{Schema: 1, ID: id, Title: generatedPageTitle(b.Name, s.Headline), Slug: b.Slug, Blocks: blocks}
	if s.Design != nil {
		artifact.Palette = map[string]string{"storefront_mode": "light"}
		for k, v := range s.Design.Palette {
			artifact.Palette[k] = v
		}
	}
	return artifact, nil
}
