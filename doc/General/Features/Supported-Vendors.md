# Supported Vendors

Here's a list of supported vendors, some might be missing.
If you are unsure of whether your device is supported or not, feel free to ask us.

```sh exec="1"
grep -h "^text: " resources/definitions/os_detection/*.yaml \
| sed -E "s/^text: *[\"']?([^\"']+).*/\1/" \
| sort -f -u \
| awk '{\
  if (last != tolower(substr($0, 0, 1))) {\
    print "\n### "toupper(substr($0,0,1))"\n* "$0; last = tolower(substr($1, 0, 1))\
  } else {\
    print "* "$0\
  }\
}'
```
